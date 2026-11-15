"""Run gallery-dl with Instagram's visual slides counted independently of music."""

import sys

from gallery_dl import exception, main
from gallery_dl.extractor.instagram import InstagramExtractor, InstagramStoriesExtractor


_parse_post = InstagramExtractor._parse_post


def story_posts(self):
    """Only the requested account's active reel, never neighbouring stories."""
    if self.highlight_id is not None:
        raise exception.ExtractionError("Only active account stories are supported")
    user = self.api.user(self.user)
    if str(user.get("username", "")).lower() != self.user.lower():
        raise exception.ExtractionError("Instagram story account mismatch")
    uid = str(user.get("id") or user.get("pk") or "")
    if not uid.isdigit():
        raise exception.ExtractionError("Invalid Instagram story account identifier")
    reels = list(self.api.reels_media((uid,)))
    if not reels:
        return ()
    own = []
    for reel in reels:
        rid = str(reel.get("id") or "")
        owner = str((reel.get("user") or {}).get("pk") or (reel.get("user") or {}).get("id") or "")
        if (rid == uid or owner == uid) and (not owner or owner == uid) and (not rid.isdigit() or rid == uid):
            own.append(reel)
    if len(own) != 1:
        raise exception.ExtractionError("Instagram did not return one matching story reel")
    reel = own[0].copy()
    items = list(reel.get("items") or ())
    if self.media_id:
        items = [item for item in items if str(item.get("pk")) == self.media_id]
        if len(items) != 1:
            raise exception.NotFoundError("story")
    if not items:
        return ()
    reel["items"] = items
    return (reel,)


def visual_post(self, post):
    # gallery-dl adds optional music and original-sound entries to _files even
    # when audio downloads are off. Its Directory count includes those entries.
    # Check the actual source slides first, so missing images cannot be hidden
    # by renumbering the remaining files.
    carousel = post.get("carousel_media")
    stories = post.get("items")
    expected = len(stories if stories is not None else (carousel or (post,)))
    limit = 100 if stories is not None else 20
    if not 1 <= expected <= limit:
        raise exception.ExtractionError("Instagram visual item count exceeds its limit")
    if carousel and post.get("carousel_media_count") is not None:
        if int(post["carousel_media_count"]) != expected:
            raise exception.ExtractionError("Instagram returned an incomplete carousel response")
    parsed = _parse_post(self, post)
    files = [item for item in parsed["_files"] if not item.get("audio_url")]
    if len(files) != expected:
        raise exception.ExtractionError(
            f"Incomplete Instagram visual slides: expected={expected} received={len(files)}"
        )
    for index, item in enumerate(files, 1):
        item["num"] = index
    parsed["_files"] = files
    return parsed


if __name__ == "__main__":
    InstagramExtractor._parse_post = visual_post
    InstagramStoriesExtractor.posts = story_posts
    sys.exit(main())
