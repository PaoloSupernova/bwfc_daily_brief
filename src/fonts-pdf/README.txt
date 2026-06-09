PLACE BRAND FONT FILES HERE (FOR PDF EXPORT)

This folder holds the .ttf or .otf versions of the BWFC brand fonts. The
mPDF library reads these directly when generating PDF exports.

REQUIRED FILES (filenames must match exactly):

  Nippo-Regular.ttf
  Nippo-Medium.ttf
  Nippo-Bold.ttf
  Satoshi-Regular.ttf
  Satoshi-Medium.ttf
  Satoshi-Bold.ttf
  BuiltTitling-Regular.ttf  (optional)

.otf files also work; the PdfExporter falls back to .otf automatically if
.ttf isn't found.

WHERE TO GET THEM:

  Nippo:    https://www.fontshare.com/fonts/nippo
  Satoshi:  https://www.fontshare.com/fonts/satoshi

The Fontshare download includes both .ttf and .otf in the family zip.
Drop the relevant .ttf files (or .otf if no .ttf is provided) into here.

DO NOT use .woff or .woff2 in this folder — mPDF cannot read those formats.
Those go in public/fonts/ for the browser to use on-screen.

If files are missing or only some weights are present, PdfExporter falls
back to Arial silently. PDFs will still generate without errors.

This file can be deleted once the fonts are in place.
