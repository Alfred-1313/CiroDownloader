"""Record the arrival time of actual AAC packets received over loopback RTMP."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time

address, root = sys.argv[1], Path(sys.argv[2])
command = ['ffmpeg', '-nostdin', '-v', 'error', '-listen', '1', '-probesize', '32', '-analyzeduration', '0',
           '-i', address, '-map', '0:a:0', '-c:a', 'copy', '-f', 'adts', '-flush_packets', '1', 'pipe:1']
with (root / 'receiver.log').open('wb') as errors, (root / 'packets.jsonl').open('w') as log, (root / 'received.aac').open('wb') as audio:
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=errors)
    buffer = b''
    try:
        while chunk := os.read(process.stdout.fileno(), 65536):
            audio.write(chunk)
            buffer += chunk
            while len(buffer) >= 7:
                if buffer[0] != 255 or buffer[1] & 0xF6 != 0xF0:
                    raise RuntimeError('Receiver output is not ADTS')
                size = ((buffer[3] & 3) << 11) | (buffer[4] << 3) | (buffer[5] >> 5)
                if size < 7:
                    raise RuntimeError('Invalid AAC packet')
                if len(buffer) < size:
                    break
                log.write(json.dumps({'t': time.monotonic(), 'bytes': size}) + '\n')
                log.flush()
                buffer = buffer[size:]
        process.wait(timeout=5)
    finally:
        if process.poll() is None:
            process.terminate()
            process.wait(timeout=5)
