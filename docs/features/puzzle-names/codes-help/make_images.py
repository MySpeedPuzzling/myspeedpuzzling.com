"""
Builds the "What is it?" images of the add form's code inputs (templates/puzzle/_codes_help_modal.html.twig):
public/img/puzzle-codes/box-side.<locale>.webp - the box side of Ravensburger 12002028 with the EAN and the brand code
boxed, the tab texts in the page's language. See ../codes-help-and-check-digit.md.

    python3 docs/features/puzzle-names/codes-help/make_images.py

Needs Pillow (with WebP). Fonts are downloaded from google/fonts on the first run. The brand code box leaves out the
digit Ravensburger prints after the code - it is the EAN's check digit, not part of the code.

A changed image needs a new file name (box-side-2.<locale>.webp): /img/* is not content-hashed and the service worker
serves images stale-while-revalidate.
"""

import pathlib
import tempfile
import urllib.request

from PIL import Image, ImageDraw, ImageFilter, ImageFont

HERE = pathlib.Path(__file__).resolve().parent
OUTPUT = HERE.parents[3] / 'public' / 'img' / 'puzzle-codes'
FONTS = pathlib.Path(tempfile.gettempdir()) / 'msp-codes-help-fonts'

PRIMARY = (0xfe, 0x69, 0x6a)  # $primary
WIDTH = 800
QUALITY = 72

# The brand code tab: forms.puzzle_identification_number of each locale
LABELS = {
    'en': 'Brand code',
    'cs': 'Kód značky',
    'de': 'Markencode',
    'es': 'Código de marca',
    'fr': 'Code de marque',
    'ja': 'ブランドコード',
}

# In box-side-source.jpg pixels
CROP = (30, 30, 1340, 840)
BRAND_CODE_BOX = (588, 170, 1006, 256)  # "12 002 028" - the check digit "8" after it stays outside
EAN_BOX = (155, 263, 1080, 552)  # bars and digits

FONT_URLS = {
    'Rubik.ttf': 'https://github.com/google/fonts/raw/main/ofl/rubik/Rubik%5Bwght%5D.ttf',
    'NotoSansJP.ttf': 'https://github.com/google/fonts/raw/main/ofl/notosansjp/NotoSansJP%5Bwght%5D.ttf',
}


def font_file(name):
    FONTS.mkdir(exist_ok=True)
    path = FONTS / name
    if not path.exists():
        urllib.request.urlretrieve(FONT_URLS[name], path)
    return path


def font_for(locale):
    font = ImageFont.truetype(str(font_file('NotoSansJP.ttf' if locale == 'ja' else 'Rubik.ttf')), 54)
    font.set_variation_by_name('Bold' if locale == 'ja' else 'SemiBold')
    return font


def smooth_paper(image):
    """The box's linen texture costs half the bytes: blur everything but the print (and a margin around it)."""
    dark = image.convert('L').point(lambda value: 255 if value < 105 else 0)
    mask = dark.filter(ImageFilter.MinFilter(3)).filter(ImageFilter.MaxFilter(11)).filter(ImageFilter.GaussianBlur(3))
    return Image.composite(image, image.filter(ImageFilter.GaussianBlur(8)), mask)


def boxed(draw, rect, label, tab_side, font, reference_font):
    x1, y1, x2, y2 = rect
    draw.rounded_rectangle(rect, radius=14, outline=PRIMARY, width=7)
    left, _, right, _ = draw.textbbox((0, 0), label, font=font)
    _, top, _, bottom = draw.textbbox((0, 0), 'EAN', font=reference_font)  # the same tab height in every locale
    tab_width, tab_height = right - left + 44, bottom - top + 28
    if tab_side == 'below':
        tab_x, tab_y = x1, y2 - 3
    else:
        tab_x, tab_y = x2 - tab_width, y1 - tab_height + 3
    draw.rounded_rectangle((tab_x, tab_y, tab_x + tab_width, tab_y + tab_height), radius=12, fill=PRIMARY)
    draw.text((tab_x + 22 - left, tab_y + tab_height / 2), label, font=font, fill='white', anchor='lm')


def main():
    source = smooth_paper(Image.open(HERE / 'box-side-source.jpg').convert('RGB'))
    OUTPUT.mkdir(parents=True, exist_ok=True)
    latin = font_for('en')

    for locale, label in LABELS.items():
        image = source.copy()
        draw = ImageDraw.Draw(image)
        boxed(draw, BRAND_CODE_BOX, label, 'above', font_for(locale), latin)
        boxed(draw, EAN_BOX, 'EAN', 'below', latin, latin)
        image = image.crop(CROP)
        image = image.resize((WIDTH, round(image.height * WIDTH / image.width)), Image.LANCZOS)
        target = OUTPUT / f'box-side.{locale}.webp'
        image.save(target, quality=QUALITY, method=6)  # Pillow writes no metadata unless asked
        print(f'{target.relative_to(HERE.parents[3])}  {image.width}x{image.height}  {target.stat().st_size // 1024} KB')


if __name__ == '__main__':
    main()
