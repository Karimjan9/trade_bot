import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from cryptography.fernet import Fernet
from telethon.sessions import StringSession

import gateway
from storage import Outbox


class OutboxTest(unittest.TestCase):
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
