"""Read-only price feed from an already authenticated Windows MT5 terminal."""
import argparse
import getpass
import os
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

import keyring
import MetaTrader5 as mt5
import requests
from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / '.env')
SERVICE = 'signal-forge.market'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['configure', 'run'])
    args = parser.parse_args()
    if args.command == 'configure':
        token = getpass.getpass('Laravel MARKET_DATA_TOKEN: ').strip()
        if len(token) < 32:
            raise ValueError('Token must have at least 32 characters')
        keyring.set_password(SERVICE, 'token', token)
        return
    token = keyring.get_password(SERVICE, 'token')
    if not token:
        raise RuntimeError('Run: python feed.py configure')
    url = os.getenv('PLATFORM_URL', 'http://127.0.0.1:8000').rstrip('/')
    parsed = urlparse(url)
    if parsed.scheme != 'https' and not (parsed.scheme == 'http' and parsed.hostname in ('localhost', '127.0.0.1', '::1')):
        raise ValueError('HTTPS or loopback HTTP is required')
    path = os.getenv('MT5_TERMINAL_PATH')
    initialized = mt5.initialize(path) if path else mt5.initialize()
    if not initialized:
        raise RuntimeError('MT5 initialization failed: ' + str(mt5.last_error()))
    mappings = [pair.split(':', 1) for pair in os.getenv('MT5_SYMBOLS', 'XAUUSD:XAUUSD').split(',')]
    last = {}
    try:
        while True:
            terminal = mt5.terminal_info()
            if not terminal or not terminal.connected:
                print('MT5 disconnected; no quotes published', flush=True)
                time.sleep(5)
                continue
            for platform_symbol, broker_symbol in mappings:
                if not mt5.symbol_select(broker_symbol, True):
                    continue
                tick = mt5.symbol_info_tick(broker_symbol)
                if not tick or tick.bid <= 0 or tick.ask < tick.bid or last.get(platform_symbol) == tick.time_msc:
                    continue
                timestamp = datetime.fromtimestamp(tick.time_msc / 1000, timezone.utc)
                if (datetime.now(timezone.utc) - timestamp).total_seconds() > 30:
                    continue
                hour = timestamp.hour
                session = 'OVERLAP' if 13 <= hour < 16 else ('LONDON' if 7 <= hour < 16 else ('NEW_YORK' if 13 <= hour < 22 else 'ASIA'))
                try:
                    response = requests.post(url + '/api/market/snapshots', json={'symbol': platform_symbol, 'bid': tick.bid, 'ask': tick.ask, 'atr': None, 'regime': 'UNKNOWN', 'session': session, 'source': 'mt5_feed', 'recorded_at': timestamp.isoformat(), 'market_open': True, 'is_historical': False}, headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/json'}, timeout=10, allow_redirects=False)
                    response.raise_for_status()
                    last[platform_symbol] = tick.time_msc
                except requests.RequestException as exc:
                    print('Quote publication failed: ' + type(exc).__name__, flush=True)
            time.sleep(max(1, float(os.getenv('POLL_SECONDS', '2'))))
    finally:
        mt5.shutdown()


if __name__ == '__main__':
    main()
