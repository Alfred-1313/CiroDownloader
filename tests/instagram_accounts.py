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
            if self.mode in ("routes_gone", "exact_graphql", "exact_wrong", "exact_limited", "exact_missing", "exact_login"):
                self.reply({}, 404)
            elif self.mode == "refused":
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
            elif self.mode in ("html_photo", "direct_bad_avatar"):
                self.reply(b"<html>not an image</html>", kind="image/jpeg")
            elif self.mode == "large_photo":
                self.reply(b"\xff\xd8\xff" + b"x" * (8 * 1024 * 1024), kind="image/jpeg")
            else:
                self.reply(b"\xff\xd8\xfffixture-profile-photo", kind="image/jpeg")
        elif parts.path == "/eliyas_sport/" and self.mode == "exact_limited":
            self.reply({}, 429)
        elif parts.path == "/eliyas_sport/" and self.mode == "exact_missing":
            self.reply({}, 404)
        elif parts.path == "/eliyas_sport/" and self.mode == "exact_login":
            self.send_response(302)
            self.send_header("Location", "/accounts/login/")
            self.end_headers()
        elif parts.path == "/eliyas_sport/" and self.mode in ("routes_gone", "direct_bad_avatar"):
            user = {"id": "700", "username": "eliyas_sport", "full_name": "الیاس اسپرت", "biography": "کیفیت اعتبار ماست",
                    "edge_owner_to_timeline_media": {"count": 581}, "edge_followed_by": {"count": 16200},
                    "edge_follow": {"count": 326}, "profile_pic_url": "https://scontent.cdninstagram.com/avatar.jpg"}
            unrelated = dict(user, username="another_shop", id="999")
            payload = {"require": [["RelayPrefetchedStreamCache", {"data": {"user": user, "suggestion": unrelated}}]]}
            self.reply(('<html><script type="application/json">'+json.dumps(payload)+'</script></html>').encode(), kind="text/html")
        else:
            if parts.path == "/eliyas_sport/" and self.mode in ("exact_graphql", "exact_wrong"):
                self.reply(b'<html>"profile_id":"700"<script id="__eqmc">{"l":"fixture-lsd","f":"fixture-dtsg"}</script><link href="https://static.cdninstagram.com/rsrc.php/fixture.js"></html>', kind="text/html")
                return
            self.reply(b'<html><script id="__eqmc">{"l":"fixture-lsd","f":"fixture-dtsg"}</script><link href="https://static.cdninstagram.com/rsrc.php/fixture.js"></html>', kind="text/html")

    def do_POST(self):
        body = parse_qs(self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode())
        self.calls.append((self.path, dict(self.headers), body))
        if self.mode == "partial_limited":
            self.reply({}, 429)
            return
        if self.mode == "partial_missing" and json.loads(body["variables"][0])["id"] == "100":
            self.reply({"data": {"user": None}})
            return
        if self.mode == "profile_refused":
            self.reply({"status": "fail"}, 403)
            return
        uid = json.loads(body["variables"][0])["id"]
        self.reply({"data": {"user": {
            "id": uid, "username": "other_shop" if self.mode == "exact_wrong" else "eliyas_sport" if uid == "700" else "mehdi" + str(int(uid) - 100), "full_name": "مهدی <test>",
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

    def test_profile_photo_is_bounded_and_not_redirected(self):
        item = {"id": "100", "username": "mehdi0"}
        with tempfile.TemporaryDirectory() as folder:
            item = self.client.profile(item)
            result = {"item": item, "photo": self.client.photo(item, Path(folder, "instagram-avatar.jpg"))}
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
            request.write_text(json.dumps({"op": "direct", "username": "mehdi"}))
            cookies.write_text("# Netscape HTTP Cookie File\n.instagram.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tfixture-secret\n")
            process = subprocess.run([sys.executable, str(SPEC.origin), "--request", str(request), "--cookies", str(cookies)], capture_output=True, text=True, timeout=15)
            self.assertEqual(process.returncode, 0)
            self.assertEqual(json.loads(process.stdout), {"error": "cookies", "stage": "runtime", "http": 0})
            self.assertNotIn("fixture-secret", process.stdout + process.stderr)

    def test_direct_profile_never_calls_search(self):
        Site.mode = "routes_gone"
        with tempfile.TemporaryDirectory() as folder:
            result = MODULE.run(self.client, {"op": "direct", "username": "eliyas_sport"}, Path(folder))
        item = result["item"]
        self.assertEqual((item["username"], item["posts"], item["followers"], item["following"]), ("eliyas_sport", 581, 16200, 326))
        self.assertEqual(item["bio"], "کیفیت اعتبار ماست")
        self.assertTrue(result["photo"])
        self.assertEqual([p for p, _, _ in Site.calls], ["/eliyas_sport/", "/avatar.jpg"])

    def test_avatar_failure_keeps_confirmed_profile_metadata(self):
        Site.mode = "direct_bad_avatar"
        with tempfile.TemporaryDirectory() as folder:
            result = MODULE.run(self.client, {"op": "direct", "username": "eliyas_sport"}, Path(folder))
        self.assertFalse(result["photo"])
        self.assertEqual(result["item"]["bio"], "کیفیت اعتبار ماست")
        self.assertEqual(result["warning"]["stage"], "avatar")

    def test_direct_profile_reuses_page_for_graphql(self):
        Site.mode = "exact_graphql"
        with tempfile.TemporaryDirectory() as folder:
            result = MODULE.run(self.client, {"op": "direct", "username": "eliyas_sport"}, Path(folder))
        self.assertEqual(result["item"]["username"], "eliyas_sport")
        self.assertEqual(sum(p == "/eliyas_sport/" for p, _, _ in Site.calls), 1)
        self.assertFalse(any("search" in p for p, _, _ in Site.calls))

    def test_direct_profile_rejects_another_account(self):
        Site.mode = "exact_wrong"
        with tempfile.TemporaryDirectory() as folder, self.assertRaises(MODULE.Failure) as caught:
            MODULE.run(self.client, {"op": "direct", "username": "eliyas_sport"}, Path(folder))
        self.assertEqual(caught.exception.reason, "unavailable")

    def test_profile_rate_limit_login_and_404_are_not_retried(self):
        for mode, reason in (("exact_limited", "blocked"), ("exact_missing", "notfound"), ("exact_login", "cookies")):
            Site.mode, Site.calls = mode, []
            with tempfile.TemporaryDirectory() as folder, self.assertRaises(MODULE.Failure) as caught:
                MODULE.run(self.client, {"op": "direct", "username": "eliyas_sport"}, Path(folder))
            self.assertEqual((caught.exception.reason, caught.exception.stage), (reason, "profile"))
            self.assertEqual(len(Site.calls), 1)

    def test_old_search_operations_and_invalid_names_make_no_requests(self):
        for request in ({"op":"search","query":"mehdi"}, {"op":"page","items":[]}, {"op":"direct","username":"name with spaces"}):
            with tempfile.TemporaryDirectory() as folder, self.assertRaises(MODULE.Failure):
                MODULE.run(self.client, request, Path(folder))
        self.assertFalse(Site.calls)


if __name__ == "__main__":
    unittest.main()
