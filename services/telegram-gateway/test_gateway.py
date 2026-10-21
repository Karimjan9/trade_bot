import tempfile
import json
import unittest
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

from cryptography.fernet import Fernet
from telethon.sessions import StringSession

import gateway
from storage import Outbox


class OutboxTest(unittest.TestCase):
    def test_video_and_round_video_are_metadata_only_without_media_download(self):
        for mime, video, round_video, caption in [('video/mp4', None, None, ''), ('application/octet-stream', object(), None, 'GOLD BUY 3300 SL 3295 TP 3310'), ('', None, object(), '')]:
            with self.subTest(mime=mime, round_video=bool(round_video)), tempfile.TemporaryDirectory() as directory:
                message = SimpleNamespace(id=1, date=datetime.now(timezone.utc), edit_date=None, message=caption, reply_to_msg_id=None,
                                          peer_id=None, document=SimpleNamespace(mime_type=mime), video=video, video_note=round_video, photo=None,
                                          to_json=lambda: json.dumps({'media': {'document': {'mime_type': mime}}}))
                adapter = gateway.Gateway.__new__(gateway.Gateway)
                adapter.client = Mock()
                adapter.outbox = Outbox(Path(directory) / 'outbox.sqlite')
                with patch.object(gateway.utils, 'get_peer_id', return_value=-100123):
                    adapter.enqueue_message({'id': 1}, message)
                payload = adapter.outbox.pending()[0][1]
                self.assertTrue(payload['raw_json']['_signal_forge_media']['video_content_skipped'])
                self.assertEqual(caption, payload['text'])
                self.assertFalse(payload['raw_json']['_signal_forge_media']['downloaded'])
                adapter.client.download_media.assert_not_called()
                adapter.client.download_file.assert_not_called()
                adapter.outbox.db.close()

    def test_events_survive_restart_and_duplicate_delivery(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'outbox.sqlite'
            outbox = Outbox(path)
            payload = {'event_id': 'test-1', 'text': 'original'}
            outbox.put(payload)
            outbox.put(payload)
            outbox.db.close()
            reopened = Outbox(path)
            rows = reopened.pending()
            self.assertEqual(1, len(rows))
            self.assertEqual(payload, rows[0][1])
            reopened.reject(rows[0][0], 'HTTP 422')
            self.assertEqual([], reopened.pending())
            self.assertEqual({'failed': 1}, reopened.counts())
            reopened.retry_failed()
            reopened.acknowledge(rows[0][0])
            self.assertEqual({}, reopened.counts())
            reopened.db.close()

    def test_session_is_encrypted_and_requires_matching_os_key(self):
        key = Fernet.generate_key().decode()
        class Client:
            class Session:
                def save(self):
                    return 'private-session-value'
            session = Session()
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'session.enc'
            with patch.object(gateway, 'SESSION_PATH', path), patch.object(gateway, 'secret', return_value=key):
                gateway.save_session(Client())
                self.assertNotIn(b'private-session-value', path.read_bytes())
                self.assertEqual(b'private-session-value', Fernet(key.encode()).decrypt(path.read_bytes()))


if __name__ == '__main__':
    unittest.main()
