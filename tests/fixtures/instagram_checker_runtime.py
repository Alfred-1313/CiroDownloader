"""Pinned native scanner/parser/CLI with only upstream responses replaced."""
import json
import os
import runpy
import sys
import time
from pathlib import Path
from gallery_dl import exception
from gallery_dl.extractor.instagram import InstagramAPI
from requests import Response

root = Path(sys.argv.pop(1))
script = sys.argv.pop(1)
mode = json.loads((root / 'mode.json').read_text())
base = (root / 'base').read_text()
args = sys.argv[1:]
cookie = args[args.index('--cookies') + 1] if '--cookies' in args else None
with (root / 'backend.jsonl').open('a') as output:
    output.write(json.dumps({'script':Path(script).name,'checker_session':bool(cookie and 'CHECKER_ONLY' in Path(cookie).read_text()),'private':bool(cookie and os.stat(cookie).st_mode & 0o777 == 0o600),'args':args}) + '\n')
user = {'pk':'7','id':'7','username':'fixture'}


def webpage(self, path):
    return '<link rel="canonical" href="https://www.instagram.com/fixture/">"profile_id":"7"'


def media_item(pk, video=False):
    return {'pk':pk,'code':'CHECK' + str(pk),'taken_at':int(time.time())-(600 if int(pk) in (100,200) else 0),'user':user,
            'image_versions2':{'candidates':[{'url':base+'/photo.jpg','width':160,'height':288}]},
            'video_versions':[{'url':base+'/clip.mp4','width':160,'height':288}] if video else []}


def pagination(self, op, variables, fieldname, path=''):
    if mode.get('blocked'):
        r=Response();r.status_code=429;r._content=b'';r._content_consumed=True
        raise exception.HttpError('HTTP 429',r)
    if op == 'PolarisProfilePostsTabContentQuery_connection':
        if variables.get('username')!='fixture':raise AssertionError('Wrong feed')
        return iter(media_item(pk) for pk in mode.get('posts',[100]))
    if op == 'PolarisStoriesV3ReelPageGalleryQuery':
        if variables.get('reel_ids')!=('7',):raise AssertionError('Wrong reel')
        return iter([{'id':'7','user':user,'seen':1700000000,'items':[media_item(pk,mode.get('story_video',True)) for pk in mode.get('stories',[200])]}])
    raise AssertionError('Unexpected API')


def media(self, code):
    pk=int(code[5:]);post=media_item(pk);post['caption']={'text':'متن چکر <b>متن اصلی</b> & نمونه'}
    count=int(mode.get('carousel',2))
    if count>1:
        post['carousel_media']=[media_item(1000+i) for i in range(count)];post['carousel_media_count']=count
    return [post]


def forbidden_profile(*args, **kwargs):
    raise AssertionError('Full profile queries are forbidden')


InstagramAPI._webpage=webpage
InstagramAPI._pagination_graphql=pagination
InstagramAPI.media=media
InstagramAPI.user_by_id=forbidden_profile
sys.argv[0]=script
runpy.run_path(script,run_name='__main__')
