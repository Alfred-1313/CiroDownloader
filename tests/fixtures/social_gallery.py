"""Raw local API fixtures; preserve the real extractor, iterator and downloader."""
import json
import os
import runpy
import sys

mode = json.load(open(MODE))
post_id = "1234567890123456789"


def record(**data):
    with open(TRACE, "a") as log:
        log.write(json.dumps(data) + "\n")


args = sys.argv[1:]
cookie = args[args.index("--cookies") + 1] if "--cookies" in args else None
record(event="command", args=args, cookie_mode=os.stat(cookie).st_mode & 0o777 if cookie else None)

if SERVICE == "tiktok":
    from gallery_dl.extractor.tiktok import TiktokExtractor, TiktokVmpostExtractor

    def response(self, url, *args, **kwargs):
        record(event="api", authenticated=bool(self.cookies.get("sessionid", domain=".tiktok.com")))
        if mode["kind"] == "blocked":
            raise self.exc.AuthorizationError("Session expired; login required")
        post = {"id": post_id, "desc": mode["description"], "createTime": 1700000000,
                "author": {"uniqueId": "fixture"}}
        if mode["kind"] == "video":
            post["video"] = {"playAddr": BASE + "/clip.mp4", "format": "mp4", "duration": 1,
                             "width": 320, "height": 180,
                             "bitrateInfo": [{"PlayAddr": {"Width": 320, "Height": 180, "UrlList": [BASE + "/clip.mp4"]}}]}
        else:
            count = {"photo": 1, "album": 3, "maximum": 35, "oversize": 36, "broken": 3, "wrong": 1}[mode["kind"]]
            images = [{"imageURL": {"urlList": [BASE + "/photo.jpg"]}, "imageWidth": 160, "imageHeight": 120} for _ in range(count)]
            if mode["kind"] == "broken":
                images[-1]["imageURL"]["urlList"] = []
            post["imagePost"] = {"images": images}
            post["music"] = {"playUrl": BASE + "/must-not-download.mp3"}
        if mode["kind"] == "wrong":
            post["id"] = "9876543210123456789"
        return {"webapp.video-detail": {"statusCode": 0, "itemInfo": {"itemStruct": post}}}

    TiktokExtractor._extract_rehydration_data = response
    TiktokVmpostExtractor.request_location = lambda self, url, **kwargs: mode["redirect"]
else:
    from gallery_dl.extractor.twitter import TwitterAPI

    def response(self, requested_id, *args, **kwargs):
        record(event="api", authenticated=self.headers["x-twitter-auth-type"] == "OAuth2Session")
        if mode["kind"] == "blocked":
            raise self.exc.AuthorizationError("Session expired; login required")
        kinds = ["video"] if mode["kind"] == "video" else ["photo", "video", "animated_gif"] if mode["kind"] in ("album", "quote", "broken") else ["hls"] if mode["kind"] == "hls" else ["photo"]
        media = []
        for index, kind in enumerate(kinds):
            item = {"id_str": str(index + 1), "type": kind, "original_info": {"width": 160, "height": 120}, "media_url_https": BASE + "/photo.jpg"}
            if kind in ("video", "animated_gif", "hls"):
                item["video_info"] = {"duration_millis": 1000, "variants": [{"url": BASE + ("/stream.m3u8" if kind == "hls" else "/clip.mp4"), "bitrate": 128000, "content_type": "application/x-mpegURL" if kind == "hls" else "video/mp4"}]}
            media.append(item)
        if mode["kind"] == "broken":
            media[-1]["video_info"]["variants"] = []
        user = {"id_str": "7", "screen_name": "fixture", "name": "Fixture", "created_at": "Wed Jan 01 00:00:00 +0000 2020", "location": "", "verified": False, "protected": False, "profile_image_url_https": "", "description": ""}
        tweet = {"rest_id": post_id, "legacy": {"id_str": post_id, "full_text": mode["description"], "lang": "fa", "entities": {}, "extended_entities": {"media": media}}, "core": {"user_results": {"result": user}}}
        if mode["kind"] == "quote":
            tweet["legacy"]["quoted_status_id_str"] = "9876543210123456789"
        if mode["kind"] == "wrong":
            tweet["rest_id"] = tweet["legacy"]["id_str"] = "9876543210123456789"
        return tweet

    def detail(self, requested_id, *args, **kwargs):
        tweet = response(self, requested_id)
        quoted = dict(tweet, rest_id="9876543210123456789", legacy=dict(tweet["legacy"], id_str="9876543210123456789"))
        return [tweet, quoted]

    TwitterAPI.tweet_result_by_rest_id = response
    TwitterAPI.tweet_detail = detail

runpy.run_path(ADAPTER, run_name="__main__")
