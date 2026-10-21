"""Telegram user-account adapter. No broker credentials or order execution."""
import argparse
import asyncio
import getpass
import json
import logging
import os
import uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

import keyring
import requests
from cryptography.fernet import Fernet
from dotenv import load_dotenv
from telethon import TelegramClient, events, utils
from telethon.sessions import StringSession
from telethon.tl.functions.channels import JoinChannelRequest

from storage import Outbox

BASE = Path(__file__).resolve().parent
load_dotenv(BASE / '.env')
LABEL = os.getenv('ACCOUNT_LABEL', 'main')
SERVICE = 'signal-forge.telegram.' + LABEL
SESSION_PATH = BASE / 'var' / (LABEL + '.session.enc')
LOG = logging.getLogger('gateway')


def secret(name):
    value = keyring.get_password(SERVICE, name)
    if not value:
        raise RuntimeError('Secret missing: ' + name + '. Run: python gateway.py configure')
    return value


def configure():
    api_id = input('Telegram api_id: ').strip()
    if not api_id.isdigit():
        raise ValueError('api_id must be numeric')
    api_hash = getpass.getpass('Telegram api_hash: ').strip()
    token = getpass.getpass('Laravel TELEGRAM_GATEWAY_TOKEN: ').strip()
    if len(api_hash) != 32 or len(token) < 32:
        raise ValueError('api_hash must be 32 characters; service token at least 32')
    for name, value in [('api_id', api_id), ('api_hash', api_hash), ('gateway_token', token)]:
        keyring.set_password(SERVICE, name, value)
    if not keyring.get_password(SERVICE, 'session_key'):
        keyring.set_password(SERVICE, 'session_key', Fernet.generate_key().decode())
    print('Secrets saved in OS keyring. Next: python gateway.py login')


def load_client():
    session = ''
    if SESSION_PATH.exists():
        session = Fernet(secret('session_key').encode()).decrypt(SESSION_PATH.read_bytes()).decode()
    return TelegramClient(StringSession(session), int(secret('api_id')), secret('api_hash'), catch_up=True)


def save_session(client):
    SESSION_PATH.parent.mkdir(parents=True, exist_ok=True)
    encrypted = Fernet(secret('session_key').encode()).encrypt(client.session.save().encode())
    temporary = SESSION_PATH.with_suffix('.tmp')
    temporary.write_bytes(encrypted)
    temporary.replace(SESSION_PATH)


async def login():
    client = load_client()
    try:
        await client.start(phone=lambda: input('Telegram phone (+998...): '), password=lambda: getpass.getpass('Telegram 2FA password: '))
        save_session(client)
        print('Encrypted Telegram session saved. Next: python gateway.py run')
    finally:
        await client.disconnect()


class Platform:
    def __init__(self):
        self.url = os.getenv('PLATFORM_URL', 'http://127.0.0.1:8000').rstrip('/')
        parsed = urlparse(self.url)
        if parsed.scheme != 'https' and not (parsed.scheme == 'http' and parsed.hostname in ('localhost', '127.0.0.1', '::1')):
            raise ValueError('Use HTTPS, or HTTP on loopback only')
        self.token = secret('gateway_token')

    async def call(self, method, path, payload=None):
        def send():
            return requests.request(method, self.url + '/api/gateway/' + path, json=payload, headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/json'}, timeout=20, allow_redirects=False)
        return await asyncio.to_thread(send)


def iso(value):
    return value.astimezone(timezone.utc).isoformat()


def media_policy(message):
    document = getattr(message, 'document', None)
    mime = getattr(document, 'mime_type', '') or ''
    video = bool(getattr(message, 'video', None) or getattr(message, 'video_note', None) or mime.startswith('video/'))
    return {'kind': 'video' if video else ('photo' if getattr(message, 'photo', None) else 'other'),
            'video_content_skipped': video, 'caption_only': video and bool((message.message or '').strip()),
            'downloaded': False}


class Gateway:
    def __init__(self, client):
        self.client = client
        self.api = Platform()
        self.outbox = Outbox(BASE / 'var' / (LABEL + '.outbox.sqlite'))
        self.channels = {}
        self.entities = {}
        self.errors = 0

    def enqueue_message(self, channel, message, kind='new', historical=False):
        posted = iso(message.date)
        occurred = iso(message.edit_date) if kind == 'edit' and message.edit_date else posted
        text = message.message or ''
        # History and realtime deliveries can race; each keeps a durable provenance.
        identity = json.dumps([LABEL, channel['id'], message.id, kind, occurred, text, historical], ensure_ascii=False)
        raw = json.loads(message.to_json())
        raw['_signal_forge_media'] = media_policy(message)
        payload = {'event_id': str(uuid.uuid5(uuid.NAMESPACE_URL, identity)), 'channel_id': channel['id'], 'telegram_id': str(utils.get_peer_id(message.peer_id)), 'message_id': message.id, 'kind': kind, 'text': text, 'raw_json': raw, 'reply_to_message_id': message.reply_to_msg_id, 'posted_at': posted, 'occurred_at': occurred, 'is_historical': historical}
        self.outbox.put(payload)

    async def sync_registry(self):
        response = await self.api.call('GET', 'channels')
        response.raise_for_status()
        registry = response.json()
        allowed_ids = {row['id'] for row in registry}
        self.channels = {peer: row for peer, row in self.channels.items() if row['id'] in allowed_ids}
        for row in registry:
            try:
                entity = self.entities.get(row['id'])
                if entity is None:
                    entity = await self.client.get_entity('@' + row['username'])
                    if not getattr(entity, 'broadcast', False):
                        raise ValueError('Only public broadcast channels are allowed')
                    await self.client(JoinChannelRequest(entity))
                    self.entities[row['id']] = entity
                peer = str(utils.get_peer_id(entity))
                self.channels[int(peer)] = row
                initial = row['status'] not in ('PAPER',) or not row['telegram_id']
                response = await self.api.call('PATCH', 'channels/' + str(row['id']), {'telegram_id': peer, 'status': 'IMPORTING' if initial else 'PAPER', 'last_error': None})
                response.raise_for_status()
                count = 0
                limit = max(1, int(os.getenv('HISTORY_LIMIT', '5000')))
                minimum = 0 if initial else int(row['last_message_id'])
                # Initial import is bounded; subsequent catch-up is complete after checkpoint.
                if initial:
                    batch = await self.client.get_messages(entity, limit=limit)
                    for message in reversed(batch):
                        if getattr(message, 'message', None) is not None:
                            self.enqueue_message(row, message, historical=True)
                            count += 1
                else:
                    async for message in self.client.iter_messages(entity, min_id=minimum, reverse=True):
                        if getattr(message, 'message', None) is not None:
                            self.enqueue_message(row, message, historical=True)
                            count += 1
                response = await self.api.call('PATCH', 'channels/' + str(row['id']), {'telegram_id': peer, 'status': 'PAPER', 'last_error': None})
                response.raise_for_status()
                if count:
                    LOG.info('Channel %s: %s historical messages queued', row['id'], count)
            except Exception as exc:
                self.errors += 1
                LOG.warning('Channel %s sync failed (%s); retrying on next refresh', row['id'], type(exc).__name__)

    async def new_message(self, event):
        channel = self.channels.get(event.chat_id)
        if channel:
            self.enqueue_message(channel, event.message)

    async def edited_message(self, event):
        channel = self.channels.get(event.chat_id)
        if channel:
            self.enqueue_message(channel, event.message, kind='edit')

    async def deleted_message(self, event):
        channel = self.channels.get(event.chat_id)
        if not channel:
            return  # Never infer the channel from a message ID alone.
        occurred = iso(datetime.now(timezone.utc))
        for message_id in event.deleted_ids:
            self.outbox.put({'event_id': str(uuid.uuid5(uuid.NAMESPACE_URL, f'{LABEL}:{channel["id"]}:delete:{message_id}')), 'channel_id': channel['id'], 'telegram_id': str(event.chat_id), 'message_id': message_id, 'kind': 'delete', 'text': '', 'raw_json': {'deleted_id': message_id}, 'reply_to_message_id': None, 'posted_at': occurred, 'occurred_at': occurred, 'is_historical': False})

    async def publish_loop(self):
        while True:
            try:
                for row_id, payload in self.outbox.pending():
                    response = await self.api.call('POST', 'events', payload)
                    if response.status_code in (200, 202):
                        self.outbox.acknowledge(row_id)
                    elif response.status_code in (403, 409, 413, 422):
                        # Retain rejected payload locally for inspection and explicit retry.
                        self.outbox.reject(row_id, 'HTTP ' + str(response.status_code))
                        LOG.warning('Event delivery rejected: HTTP %s, retained in outbox', response.status_code)
                    else:
                        response.raise_for_status()
                counts = self.outbox.counts()
                heartbeat = await self.api.call('POST', 'heartbeat', {'status': 'DEGRADED' if counts.get('failed', 0) or self.errors else 'ONLINE', 'metadata': {'outbox': counts, 'whitelisted_channels': len(self.channels), 'sync_errors': self.errors}})
                heartbeat.raise_for_status()
            except Exception as exc:
                LOG.warning('Platform unavailable (%s); durable outbox retained', type(exc).__name__)
            await asyncio.sleep(5)

    async def registry_loop(self):
        while True:
            self.errors = 0
            try:
                await self.sync_registry()
                save_session(self.client)
            except Exception as exc:
                self.errors += 1
                LOG.warning('Registry unavailable (%s)', type(exc).__name__)
            await asyncio.sleep(max(10, int(os.getenv('REFRESH_SECONDS', '30'))))

    async def run(self):
        await self.client.connect()
        if not await self.client.is_user_authorized():
            raise RuntimeError('Telegram session missing or expired. Run: python gateway.py login')
        self.client.add_event_handler(self.new_message, events.NewMessage())
        self.client.add_event_handler(self.edited_message, events.MessageEdited())
        self.client.add_event_handler(self.deleted_message, events.MessageDeleted())
        try:
            await asyncio.gather(self.registry_loop(), self.publish_loop(), self.client.run_until_disconnected())
        finally:
            save_session(self.client)
            await self.client.disconnect()


def main():
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    logging.getLogger('telethon').setLevel(logging.WARNING)
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['configure', 'login', 'run', 'retry-outbox'])
    args = parser.parse_args()
    if args.command == 'configure':
        configure()
    elif args.command == 'login':
        asyncio.run(login())
    elif args.command == 'retry-outbox':
        Outbox(BASE / 'var' / (LABEL + '.outbox.sqlite')).retry_failed()
        print('Failed events returned to pending.')
    else:
        asyncio.run(Gateway(load_client()).run())


if __name__ == '__main__':
    main()
