# Delete Them

A simple web app that lets you upload a photo, mark a face with an X, and request removal. This prototype uses a local pixelation fallback (GD in PHP) while leaving a stub for a future Google Gemini 2.5 integration.

## Quick start (PHP dev server)

1. Ensure PHP 8+ is installed with GD extension.
2. From the project root, run:

```bash
php -S localhost:2222 -t public
```

3. Open `http://localhost:2222` in your browser.

## How it works

- Frontend: HTML/CSS/JS with canvas overlay to place/remove X markers (center and radius). Drag & drop or browse to upload. Click on the image to add an X; click near an existing X to remove it. Use the slider to adjust radius before placing.
- Backend: `public/api/process.php` accepts the uploaded image and markers, and currently pixelates a circular-ish region around each marker using a square bounding box as a simple local fallback. It returns a JPEG image. If configured with an API key in `public/api/config.php`, it attempts a Gemini inpainting call first, then falls back to GD.
- Config: `public/api/config.php` includes your API key (local dev only; do not commit secrets in production).

## File structure

```
public/
  index.html
  assets/
    css/styles.css
    js/app.js
  api/
    process.php
    config.php
```

## Environment (future integration)

- `GEMINI_API_KEY`: Your Gemini API key
- `GEMINI_MODEL` (optional): Model name, e.g. `gemini-2.5-pro` or similar

## Roadmap

- Replace pixelation fallback with real person removal via Gemini 2.5 image edit API
- Add multi-face detection assist (auto suggest markers)
- Add history and download options
- Add progress indicator for longer operations


