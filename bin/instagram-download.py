"""Run gallery-dl with Instagram's visual slides counted independently of music."""

import sys

from gallery_dl import exception, main
from gallery_dl.extractor.instagram import InstagramExtractor


_parse_post = InstagramExtractor._parse_post


def visual_post(self, post):
    # gallery-dl adds optional music and original-sound entries to _files even
    # when audio downloads are off. Its Directory count includes those entries.
    # Check the actual source slides first, so missing images cannot be hidden
    # by renumbering the remaining files.
    carousel = post.get("carousel_media")
    expected = len(carousel or (post,))
    if not 1 <= expected <= 20:
        raise exception.ExtractionError("Instagram post must contain between 1 and 20 visual slides")
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
    sys.exit(main())
