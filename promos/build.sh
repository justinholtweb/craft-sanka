#!/usr/bin/env bash
# Renders the Plugin Store promo images from slides.html.
# Output: promos/out/sanka-promo-N.png at 1920x1080 (rendered at 2x, downsampled).
set -euo pipefail

cd "$(dirname "$0")"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
SLIDES=${1:-"1 2 3 4 5 6 7 8"}

# Inline the self-hosted fonts so Chrome's file:// origin rules can't block them.
{
  echo "@font-face{font-family:'Inter';font-style:normal;font-weight:400 700;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/inter-latin.woff2 | tr -d '\n')) format('woff2');}"
  echo "@font-face{font-family:'Jersey 20';font-style:normal;font-weight:400;font-display:block;src:url(data:font/woff2;base64,$(base64 < assets/jersey-20-latin.woff2 | tr -d '\n')) format('woff2');}"
} > fonts.css

mkdir -p out
# Chrome's disk cache keys file:// loads by URL, so re-rendering after an edit can
# silently screenshot the previous version. The stamp makes every load unique.
STAMP=$(date +%s)

for n in $SLIDES; do
  "$CHROME" \
    --headless=new \
    --disable-gpu \
    --hide-scrollbars \
    --allow-file-access-from-files \
    --force-device-scale-factor=2 \
    --window-size=1920,1080 \
    --virtual-time-budget=5000 \
    --screenshot="out/sanka-promo-$n.png" \
    "file://$PWD/slides.html?s=$n&v=$STAMP" >/dev/null 2>&1
  sips --resampleWidth 1920 "out/sanka-promo-$n.png" >/dev/null
  echo "  built out/sanka-promo-$n.png"
done
