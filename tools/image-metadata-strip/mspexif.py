"""Shared pieces of the 2026-09-30 image-metadata audit and strip job (myspeedpuzzling bucket).

- S3 client for the private Hetzner bucket (credentials from the app's rendered .env).
- A persistent exiftool process (13.59, -stay_open) per worker thread.
- classify(): one verdict per file from exiftool's JSON, shared by the audit and the strip job so
  both agree on what "carries metadata" means.

Policy (what must go vs what may stay):
  stays   - everything a decoder needs to show the same picture: ICC profile, JFIF, Adobe APP14,
            PNG structure + gAMA/cHRM/sRGB/bKGD/pHYs, WebP/HEIF container structure, and the EXIF
            Orientation (+ the few resolution/colour-space tags that ride along with it);
  goes    - GPS, camera make/model/serial, capture dates, owner/artist, maker notes, the embedded
            EXIF thumbnail (IFD1), XMP (except the bare toolkit marker), IPTC/Photoshop records,
            comments, MPF secondary images and other JPEG trailers, PNG text chunks with content.
"""
import atexit
import json
import os
import re
import shutil
import subprocess
import threading

import boto3
from botocore.config import Config

# Overridable only for the local rehearsal against a throwaway MinIO
BUCKET = os.environ.get('MSP_BUCKET', 'myspeedpuzzling')
ENDPOINT = os.environ.get('MSP_S3_ENDPOINT', 'https://fsn1.your-objectstorage.com')
WORKDIR = '/root/msp-exif-2026-09-30'
EXIFTOOL = os.environ.get('MSP_EXIFTOOL', WORKDIR + '/Image-ExifTool-13.59/exiftool')


def load_env(path=os.environ.get('MSP_ENV_FILE', '/srv/myspeedpuzzling/.env')):
    env = {}
    for line in open(path):
        line = line.strip()
        if '=' in line and not line.startswith('#'):
            k, v = line.split('=', 1)
            env[k] = v.strip().strip("'").strip('"')
    return env


def s3_client(max_pool=64):
    env = load_env()
    return boto3.client(
        's3', endpoint_url=ENDPOINT, region_name='fsn1',
        aws_access_key_id=env['S3_ACCESS_KEY'], aws_secret_access_key=env['S3_SECRET_KEY'],
        config=Config(max_pool_connections=max_pool, retries={'max_attempts': 6, 'mode': 'standard'}),
    )


_instances = []


class ExifTool:
    """One long-running exiftool (-stay_open); not thread-safe - one instance per thread."""

    def __init__(self):
        command = ['perl', EXIFTOOL, '-stay_open', 'True', '-@', '-']
        # An exiftool -stay_open whose parent died polls its closed stdin forever (133 piled up on
        # 2026-09-30): on Linux it gets SIGTERM when the thread that started it goes away
        if shutil.which('setpriv'):
            command = ['setpriv', '--pdeathsig', 'TERM'] + command
        self.proc = subprocess.Popen(
            command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        )
        _instances.append(self)

    def run(self, args):
        """Runs one exiftool command; returns its stdout (text)."""
        payload = '\n'.join(args) + '\n-execute\n'
        self.proc.stdin.write(payload.encode('utf-8'))
        self.proc.stdin.flush()
        out = []
        while True:
            line = self.proc.stdout.readline()
            if not line:
                raise RuntimeError('exiftool exited')
            if line.strip() == b'{ready}':
                break
            out.append(line)
        return b''.join(out).decode('utf-8', 'replace')

    def tags(self, path, fast=False, embedded=False):
        args = ['-j', '-G0:1', '-a', '-n', '-api', 'LargeFileSupport=1']
        if fast:
            args.append('-fast')
        if embedded:
            args.append('-ee')  # also inside MPF secondary images, JPEG trailers, HEIF auxiliary items
        out = self.run(args + [path])
        try:
            return json.loads(out)[0] if out.strip() else {}
        except ValueError:
            return {}

    def image_data_hash(self, path):
        out = self.run(['-s3', '-api', 'ImageHashType=SHA256', '-ImageDataHash', path])
        return out.strip()

    def close(self):
        try:
            self.proc.stdin.write(b'-stay_open\nFalse\n')
            self.proc.stdin.flush()
            self.proc.wait(timeout=10)
        except Exception:
            self.proc.kill()


def close_all():
    for instance in _instances:
        instance.close()


atexit.register(close_all)

_local = threading.local()


def exiftool():
    et = getattr(_local, 'et', None)
    if et is None:
        et = _local.et = ExifTool()
    return et


# --- classification ----------------------------------------------------------------------------

# EXIF tags that describe the pixels, not the person or the camera.
EXIF_TECHNICAL = {
    'IFD0:Orientation', 'IFD0:XResolution', 'IFD0:YResolution', 'IFD0:ResolutionUnit',
    'IFD0:YCbCrPositioning',
    # the encoding program ("Google", "Adobe Photoshop 25.0") - removed with the rest when ExifTool can,
    # left alone when it is all there is (e.g. a zTXt raw profile ExifTool cannot rewrite)
    'IFD0:Software',
    'ExifIFD:ColorSpace', 'ExifIFD:ExifImageWidth', 'ExifIFD:ExifImageHeight', 'ExifIFD:ExifVersion',
    'ExifIFD:ComponentsConfiguration', 'ExifIFD:FlashpixVersion',
    'InteropIFD:InteropIndex', 'InteropIFD:InteropVersion',
}
# PNG chunks exiftool reports that are structure / colour, plus ImageMagick's own write timestamps.
PNG_TECHNICAL = {
    'ImageWidth', 'ImageHeight', 'BitDepth', 'ColorType', 'Compression', 'Filter', 'Interlace',
    'Gamma', 'WhitePointX', 'WhitePointY', 'RedX', 'RedY', 'GreenX', 'GreenY', 'BlueX', 'BlueY',
    'BackgroundColor', 'PixelsPerUnitX', 'PixelsPerUnitY', 'PixelUnits', 'SRGBRendering',
    'SignificantBits', 'Transparency', 'Palette', 'ProfileName', 'AnimationFrames', 'AnimationPlays',
    'Datecreate', 'Datemodify', 'Datetimestamp', 'ModifyDate', 'Software', 'VirtualImageWidth',
    'VirtualImageHeight', 'ImageOffset', 'StereoMode', 'CICodePoints', 'ColorPrimaries',
    'TransferCharacteristics', 'MatrixCoefficients', 'VideoFullRangeFlag', 'SuggestedPalette',
    'HistogramData', 'OriginalImageWidth', 'OriginalImageHeight',
    # iOS screenshots: the iDOT chunk (offsets for parallel decoding) - exiftool cannot drop it
    'AppleDataOffsets',
    # ImageMagick's vpAg chunk
    'VirtualPageUnits',
}
XMP_TECHNICAL = {'XMP-x:XMPToolkit'}
# iPhone HEIC auxiliary images (HDR gain map, depth map, portrait / segmentation mattes) carry their
# own XMP: versions, headroom, gain-map ranges, pixel formats, depth quality, lens calibration, the
# portrait blur settings. ExifTool cannot remove it from the auxiliary items; none of it says anything
# about who, where or when.
XMP_TECHNICAL_GROUPS = {'XMP-HDRGainMap', 'XMP-hdrgm', 'XMP-apdi', 'XMP-portraitEffectsMatte',
                        'XMP-semanticSegmentationMatte', 'XMP-depthData', 'XMP-depthBlurEffect',
                        'XMP-portraitLightingEffect'}
IPTC_TECHNICAL = {'CodedCharacterSet', 'ApplicationRecordVersion', 'EnvelopeRecordVersion'}
# Family-0 groups that only ever describe the file or its decoding.
STRUCTURAL_GROUPS = {'SourceFile', 'ExifTool', 'File', 'JFIF', 'Adobe', 'ICC_Profile', 'Composite',
                     'RIFF', 'GIF', 'JPEG'}
QUICKTIME_PRIVATE = re.compile(r'(GPS|Location|Make|Model|Software|Creat|Author|Artist|Owner|Serial|Date)',
                               re.I)
GPS_TAG = re.compile(r'^GPS(Latitude|Longitude|Position|Coordinates)$')


def _nonzero(value):
    if isinstance(value, (int, float)):
        return value != 0
    if isinstance(value, str):
        nums = re.findall(r'-?\d+(?:\.\d+)?', value)
        return any(float(n) != 0 for n in nums) if nums else bool(value.strip())
    if isinstance(value, list):
        return any(_nonzero(v) for v in value)
    return value is not None


def classify(tags):
    """Verdict for one exiftool -j -G0:1 -a -n record."""
    private, warnings = [], []
    gps = False
    exif_any = False
    orientation = None
    make = model = date = ''
    has_icc = False
    fmt = tags.get('File:FileType', '')
    for key, value in tags.items():
        if key == 'SourceFile':
            continue
        parts = key.split(':')
        g0, tag = parts[0], parts[-1]
        g1 = parts[1] if len(parts) == 3 else g0
        if g0 == 'ExifTool':
            if tag in ('Warning', 'Error'):
                warnings.append(str(value)[:120])
            continue
        if g0 != 'Composite' and GPS_TAG.match(tag) and _nonzero(value):
            gps = True
        # An empty tag says nothing (e.g. blank Artist / Copyright written by an editor)
        if isinstance(value, str) and value.strip() == '':
            continue
        if g0 == 'ICC_Profile':
            has_icc = True
        if g0 == 'EXIF':
            exif_any = True
            if key == 'EXIF:IFD0:Orientation':
                orientation = value
            if tag == 'Make':
                make = str(value)
            if tag == 'Model':
                model = str(value)
            if tag == 'DateTimeOriginal':
                date = str(value)
            if f'{g1}:{tag}' not in EXIF_TECHNICAL:
                private.append(f'{g1}:{tag}')
            continue
        if g0 == 'MakerNotes':
            exif_any = True
            private.append(f'MakerNotes:{g1}')
            continue
        if g0 == 'XMP':
            if f'{g1}:{tag}' not in XMP_TECHNICAL and g1 not in XMP_TECHNICAL_GROUPS:
                private.append(f'XMP:{g1}:{tag}')
            continue
        if g0 == 'IPTC':
            if tag not in IPTC_TECHNICAL:
                private.append(f'IPTC:{tag}')
            continue
        if g0 == 'PNG':
            if tag not in PNG_TECHNICAL:
                private.append(f'PNG:{tag}')
            continue
        if g0 == 'QuickTime':
            if QUICKTIME_PRIVATE.search(tag):
                private.append(f'QuickTime:{tag}')
            continue
        if g0 == 'File':
            if tag == 'Comment':
                private.append('File:Comment')
            continue
        if g0 in STRUCTURAL_GROUPS:
            continue
        # JPEG APP14 "Adobe": the colour transform a decoder needs (ExifTool keeps it on purpose)
        if g0 == 'APP14' and g1 == 'Adobe':
            continue
        # iPhone HEIC: Apple's HDR tone-mapping plist (gains, histogram percentiles) that ExifTool
        # cannot remove - allowed only while every value is a number, a flag or binary data
        if g0 == 'PLIST':
            if isinstance(value, str) and not value.startswith('(Binary data') \
                    and value not in ('True', 'False') and not re.fullmatch(r'-?[0-9.eE+-]+', value):
                private.append(f'PLIST:{tag}')
            continue
        # MPF (secondary images), Photoshop, FlashPix, unknown APPn segments, JUMBF/C2PA, trailers ...
        private.append(f'{g0}:{g1}')
    uniq = sorted(set(private))
    return {
        'format': fmt,
        'mime': tags.get('File:MIMEType', ''),
        'needs_strip': bool(uniq),
        'exif': exif_any,
        'exif_private': any(p.split(':')[0] in ('IFD0', 'IFD1', 'ExifIFD', 'GPS', 'InteropIFD', 'SubIFD',
                                                 'SubIFD1', 'SubIFD2', 'GlobParamIFD', 'MakerNotes')
                            or p.startswith('MakerNotes') for p in uniq),
        'gps': gps,
        'xmp': any(p.startswith('XMP:') for p in uniq),
        'iptc': any(p.startswith(('IPTC:', 'Photoshop:')) for p in uniq),
        'comment': any(p in ('File:Comment',) or p.startswith('PNG:') for p in uniq),
        'secondary': any(p.startswith(('MPF:', 'Trailer', 'Samsung')) for p in uniq),
        'orientation': orientation,
        'make': make,
        'model': model,
        'date': date,
        'has_icc': has_icc,
        'reasons': uniq,
        'warnings': warnings,
        'width': tags.get('Composite:ImageSize', ''),
    }
