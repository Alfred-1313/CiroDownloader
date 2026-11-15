"""Real pinned gallery-dl requests against a local Instagram HTTP fixture."""

import importlib.util
import json
import logging
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

from gallery_dl import cache, config, exception, extractor
from gallery_dl.extractor.common import CACHE_MEMORY

SPEC = importlib.util.spec_from_file_location("instagram_users", Path(__file__).resolve().parents[1] / "bin/instagram-users.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
logging.disable(logging.CRITICAL)


class Site(BaseHTTPRequestHandler):
    mode = "ok"
    calls = []

    def log_message(self, *args):
        pass

    def reply(self, body, code=200, kind="application/json"):
        self.send_response(code)
        self.send_header("Content-Type", kind)
        self.end_headers()
        self.wfile.write(body if isinstance(body, bytes) else json.dumps(body).encode())

    def do_GET(self):
        parts = urlsplit(self.path)
        self.calls.append((parts.path, dict(self.headers), parse_qs(parts.query)))
        if "topsearch" in parts.path:
            if self.mode == "refused":
                self.reply({"status": "fail", "message": "login_required"}, 403)
            elif self.mode == "blocked":
                self.reply({"status": "fail"}, 429)
            elif self.mode == "login":
                self.reply({"status": "fail", "message": "login_required"})
            elif self.mode == "bad":
                self.reply({"status": "ok", "unexpected": []})
            elif self.mode == "empty":
                self.reply({"status": "ok", "users": []})
            elif self.mode == "old" and parts.path.startswith("/api/"):
                self.reply({}, 404)
            else:
                users = [{"user": {"pk": str(100 + n), "username": f"mehdi{n}", "full_name": "مهدی"}} for n in range(15)]
                users.insert(1, users[0])
                users.insert(0, {"user": {"pk": "99", "username": "unsafe/<html>"}})
                self.reply({"status": "ok", "users": users})
        elif parts.path == "/rsrc.php/fixture.js":
            self.reply(b'__d("PolarisProfilePageContentQuery_instagramRelayOperation",[],(function(a,b,c,d,e,f){e.exports="fixture-document"}));', kind="text/javascript")
        elif parts.path == "/avatar.jpg":
            if self.mode == "redirect":
                self.send_response(302)
                self.send_header("Location", "http://127.0.0.1/private")
                self.end_headers()
            elif self.mode == "html_photo":
                self.reply(b"<html>not an image</html>", kind="image/jpeg")
            elif self.mode == "large_photo":
                self.reply(b"\xff\xd8\xff" + b"x" * (8 * 1024 * 1024), kind="image/jpeg")
            else:
                self.reply(b"\xff\xd8\xfffixture-profile-photo", kind="image/jpeg")
        else:
            self.reply(b'<html><script id="__eqmc">{"l":"fixture-lsd","f":"fixture-dtsg"}</script><link href="https://static.cdninstagram.com/rsrc.php/fixture.js"></html>', kind="text/html")

    def do_POST(self):
        body = parse_qs(self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode())
        self.calls.append((self.path, dict(self.headers), body))
        if self.mode == "profile_refused":
            self.reply({"status": "fail"}, 403)
            return
        uid = json.loads(body["variables"][0])["id"]
        self.reply({"data": {"user": {
            "id": uid, "username": "mehdi" + str(int(uid) - 100), "full_name": "مهدی <test>",
            "edge_owner_to_timeline_media": {"count": 42}, "edge_followed_by": {"count": 12345},
            "edge_follow": {"count": 300}, "is_private": True, "biography": "خط اول\nخط دوم <b>literal</b>",
            "profile_pic_url_hd": "https://scontent.cdninstagram.com/avatar.jpg",
        }}})


class Accounts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), Site)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()

    def setUp(self):
        Site.mode = "ok"
        Site.calls = []
        CACHE_MEMORY.clear()
        config.clear()
        config.set(("cache",), "file", ":memory:")
        config.set(("extractor", "instagram"), "user-cache", "memory")
        config.set(("extractor", "instagram"), "sleep-request", 0)
        config.set(("extractor", "instagram"), "sleep-429", 0)
        config.set(("extractor", "instagram"), "retries", 0)
        self.extr = extractor.find("https://www.instagram.com/instagram/info")
        self.extr.initialize()
        self.extr.cookies.set("sessionid", "fixture-session-never-real", domain=".instagram.com")
        original = self.extr.session.send

        def send(request, **kwargs):
            # Requests prepares cookies/headers for the real origin before the
            # wire is routed to our HTTP fixture. No external connection occurs.
            parts = urlsplit(request.url)
            request.url = f"http://127.0.0.1:{self.server.server_port}{parts.path}"
            if parts.query:
                request.url += "?" + parts.query
            kwargs["proxies"] = {}
            return original(request, **kwargs)

        self.extr.session.send = send
        self.client = MODULE.Client(self.extr)

    def tearDown(self):
        self.extr.session.close()

    def test_search_and_current_profile_query(self):
        with tempfile.TemporaryDirectory() as folder:
            result = MODULE.run(self.client, {"op": "search", "query": "mehdi"}, Path(folder))
        items = result["items"]
        self.assertEqual(len(items), 12)
        self.assertEqual([u["username"] for u in items[:2]], ["mehdi0", "mehdi1"])
        self.assertEqual((items[0]["posts"], items[0]["followers"], items[0]["following"]), (42, 12345, 300))
        self.assertTrue(items[0]["private"])
        self.assertEqual(items[0]["bio"], "خط اول\nخط دوم <b>literal</b>")
        self.assertIsNone(items[6]["posts"])
        self.assertEqual(sum(p == "/api/graphql" for p, _, _ in Site.calls), 6)
        self.assertTrue(any(p == "/mehdi0/" for p, _, _ in Site.calls))
        search = Site.calls[0]
        self.assertEqual(search[2]["query"], ["mehdi"])
        self.assertEqual(search[1]["X-IG-App-ID"], "936619743392459")
        self.assertIn("sessionid=fixture-session-never-real", search[1]["Cookie"])
        self.assertFalse(any("/feed/" in p or "/followers/" in p for p, _, _ in Site.calls))
        self.assertFalse(Path(folder, "cache.sqlite3").exists())

    def test_profile_photo_is_bounded_and_not_redirected(self):
        item = {"id": "100", "username": "mehdi0"}
        with tempfile.TemporaryDirectory() as folder:
            result = MODULE.run(self.client, {"op": "profile", "item": item}, Path(folder))
            self.assertTrue(result["photo"])
            path = Path(folder, "instagram-avatar.jpg")
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertTrue(path.read_bytes().startswith(b"\xff\xd8\xff"))
            # An Instagram cookie is never attached to an avatar's CDN origin.
            avatar_call = next(c for c in Site.calls if c[0] == "/avatar.jpg")
            self.assertNotIn("sessionid", avatar_call[1].get("Cookie", ""))
            for mode in ("redirect", "html_photo", "large_photo"):
                Site.mode = mode
                with self.assertRaises(MODULE.Failure):
                    self.client.photo(result["item"], path)
            self.assertFalse(any(p == "/private" for p, _, _ in Site.calls))

    def test_missing_route_only_falls_back_on_404(self):
        Site.mode = "old"
        self.assertEqual(len(self.client.search("mehdi")), 12)
        self.assertEqual([p for p, _, _ in Site.calls], ["/api/v1/web/search/topsearch/", "/web/search/topsearch/"])
        for mode in ("refused", "blocked"):
            Site.mode = mode
            Site.calls = []
            with self.assertRaises(exception.HttpError):
                self.client.search("mehdi")
            self.assertEqual(len(Site.calls), 1)

    def test_empty_malformed_and_login_are_different(self):
        Site.mode = "empty"
        self.assertEqual(self.client.search("nobody"), [])
        for mode, reason in (("bad", "format"), ("login", "cookies")):
            Site.mode = mode
            with self.assertRaises(MODULE.Failure) as caught:
                self.client.search("mehdi")
            self.assertEqual(caught.exception.reason, reason)

    def test_avatar_and_number_validation(self):
        for url in ("https://cdninstagram.com.evil.test/a", "https://127.0.0.1/a", "http://scontent.cdninstagram.com/a", "https://user:pass@scontent.cdninstagram.com/a", "https://scontent.cdninstagram.com:123/a"):
            self.assertFalse(MODULE.avatar_url(url))
        self.assertTrue(MODULE.avatar_url("https://scontent.xx.fbcdn.net/a"))
        self.assertIsNone(MODULE.count(None))
        self.assertIsNone(MODULE.count(True))
        self.assertIsNone(MODULE.count(-1))
        self.assertEqual(MODULE.count(0), 0)

    def test_cli_missing_session_is_sanitized(self):
        with tempfile.TemporaryDirectory() as folder:
            request = Path(folder, "request.json")
            cookies = Path(folder, "cookies.txt")
            request.write_text(json.dumps({"op": "search", "query": "mehdi"}))
            cookies.write_text("# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tfixture-secret\n")
            process = subprocess.run([sys.executable, str(SPEC.origin), "--request", str(request), "--cookies", str(cookies)], capture_output=True, text=True, timeout=15)
            self.assertEqual(process.returncode, 0)
            self.assertEqual(json.loads(process.stdout), {"error": "cookies"})
            self.assertNotIn("fixture-secret", process.stdout + process.stderr)


if __name__ == "__main__":
    unittest.main()
