"""Download native media from the requested X post, without expanding threads."""

import sys
from urllib.parse import urlsplit

from gallery_dl import exception, main
from gallery_dl.extractor.message import Message
from gallery_dl.extractor.twitter import TwitterExtractor, TwitterTweetExtractor

original_files = TwitterExtractor._extract_files
original_items = TwitterExtractor.items
original_tweets = TwitterTweetExtractor.tweets


def requested_tweet(self):
    for tweet in original_tweets(self):
        data = tweet.get("legacy", tweet)
        if str(data.get("id_str") or tweet.get("rest_id")) == self.tweet_id:
            yield tweet
            return
    raise exception.ExtractionError("X did not return the requested post")


def complete_media(self, data, tweet):
    if str(data.get("id_str")) != self.tweet_id:
        raise exception.ExtractionError("X returned a different post")
    expected = len(data.get("extended_entities", {}).get("media", ()))
    if not 1 <= expected <= 20:
        raise exception.ExtractionError("X post has no supported native visual media")
    files = original_files(self, data, tweet)
    if len(files) != expected or any(not media.get("url") for media in files):
        raise exception.ExtractionError("X returned incomplete visual media")
    for media in files:
        if urlsplit(media["url"]).path.lower().endswith(".m3u8"):
            media.update(url="ytdl:" + media["url"], extension="mp4", _ytdl_manifest="hls")
    return files


def visual_post(self):
    description = ""
    sent = expected = 0
    for message in original_items(self):
        if message[0] == Message.Directory:
            if expected:
                raise exception.ExtractionError("X unexpectedly returned multiple posts")
            expected = message[2]["count"]
            description = message[2].get("content") or ""
            yield Message.Directory, message[1], dict(message[2], description=description)
        elif message[0] == Message.Url:
            sent += 1
            yield Message.Url, message[1], dict(message[2], description=description)
        else:
            raise exception.ExtractionError("X post unexpectedly queued another resource")
    if not expected or sent != expected:
        raise exception.ExtractionError("X returned incomplete visual media")


if __name__ == "__main__":
    TwitterTweetExtractor.tweets = requested_tweet
    TwitterTweetExtractor._extract_files = complete_media
    TwitterTweetExtractor.items = visual_post
    sys.exit(main())
