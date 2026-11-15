import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("youtube_post", Path(__file__).parents[1] / "bin/youtube-post.py")
post = importlib.util.module_from_spec(spec)
spec.loader.exec_module(post)
ID = "UgkxcNOh_kIeoLnJzcsUSE6xIg1-fz_PrvBY"


def image(url):
    return {"backstageImageRenderer": {"image": {"thumbnails": [{"url": url, "width": 1200}]}}}


class PostParserTests(unittest.TestCase):
    def test_configured_proxy_supports_socks_without_optional_pysocks(self):
        with post.http_session() as session:
            adapter = session.get_adapter("https://www.youtube.com")
            for proxy in ("socks4://127.0.0.1:1080", "socks5://127.0.0.1:1080", "http://127.0.0.1:8080"):
                self.assertIsNotNone(adapter.proxy_manager_for(proxy))
            self.assertFalse(session.trust_env)

    def test_only_exact_post_attachments_are_returned(self):
        own = {"postId": ID, "contentText": {"runs": [{"text": "own text"}]},
               "backstageAttachment": {"postMultiImageRenderer": {"images": [image("https://yt3.ggpht.com/one"), image("https://yt3.googleusercontent.com/two")]}}}
        other = {"postId": "Ug_OTHER", "backstageAttachment": image("https://yt3.ggpht.com/foreign")}
        result = post.parse({"contents": [{"backstagePostRenderer": other}, {"backstagePostRenderer": own}]}, ID)
        self.assertEqual(result["images"], ["https://yt3.ggpht.com/one", "https://yt3.googleusercontent.com/two"])
        self.assertEqual(result["description"], "own text")

    def test_missing_or_conflicting_requested_post_is_rejected(self):
        for data in [{}, {"a": {"postRenderer": {"postId": ID}}, "b": {"postRenderer": {"postId": ID, "contentText": {"simpleText": "different"}}}}]:
            with self.assertRaises(post.PostError):
                post.parse(data, ID)

    def test_untrusted_image_cannot_be_fetched(self):
        for url in ["http://yt3.ggpht.com/image", "https://yt3.ggpht.com.evil/image", "https://user@yt3.ggpht.com/image", "https://127.0.0.1/image", "https://yt3.ggpht.com:443/image"]:
            with self.assertRaises(post.PostError):
                post.parse({"postRenderer": {"postId": ID, "backstageAttachment": image(url)}}, ID)

    def test_polls_and_video_links_remain_text(self):
        for attachment, expected in [({"pollRenderer": {"choices": [{"text": {"simpleText": "First"}}, {"text": {"simpleText": "Second"}}]}}, "• First\n• Second"),
                                     ({"videoRenderer": {"videoId": "ybNMMK5Q318"}}, "https://www.youtube.com/watch?v=ybNMMK5Q318")]:
            result = post.parse({"postRenderer": {"postId": ID, "contentText": {"simpleText": "Question"}, "backstageAttachment": attachment}}, ID)
            self.assertEqual(result["images"], [])
            self.assertIn(expected, result["description"])

    def test_redirect_never_leaves_the_allowed_hosts(self):
        class Response:
            status_code = 302
            headers = {"Location": "http://127.0.0.1/private"}
            def close(self): pass
        class Session:
            calls = 0
            def get(self, *args, **kwargs):
                self.calls += 1
                return Response()
        session = Session()
        with self.assertRaises(post.PostError):
            post.response(session, "https://yt3.ggpht.com/image", post.IMAGE_HOSTS)
        self.assertEqual(session.calls, 1)

    def test_stream_size_limit_closes_the_response(self):
        class Response:
            closed = False
            def iter_content(self, size): return iter([b"123", b"456"])
            def close(self): self.closed = True
        response = Response()
        with self.assertRaises(post.PostError):
            b"".join(post.chunks(response, 5))
        self.assertTrue(response.closed)


if __name__ == "__main__":
    unittest.main()
