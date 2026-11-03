"""Build the Persian guide with embedded Vazirmatn fonts.

uv run --with playwright --with pymupdf python docs/build_guide.py
Uses an installed Chrome/Edge browser; does not download a browser.
"""
import asyncio
import json
import os
from pathlib import Path
import shutil

import pymupdf
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parent

def browser_path():
    configured = os.environ.get("PDF_BROWSER")
    candidates = [configured, shutil.which("google-chrome"), shutil.which("chromium"),
                  r"C:\Program Files\Google\Chrome\Application\chrome.exe",
                  r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"]
    for candidate in candidates:
        if candidate and Path(candidate).is_file():
            return candidate
    raise RuntimeError("Set PDF_BROWSER to a local Chrome or Edge executable")

async def main():
    output = ROOT / "youtube-cookie-guide-fa.pdf"
    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch(executable_path=browser_path(), headless=True)
        page = await browser.new_page(viewport={"width": 1000, "height": 1400}, device_scale_factor=1)
        await page.goto((ROOT / "youtube-cookie-guide-fa.html").as_uri(), wait_until="networkidle")
        await page.emulate_media(media="print")
        await page.evaluate("document.fonts.ready")
        fonts_ok = await page.evaluate("document.fonts.check('12px Vazirmatn') && document.fonts.check('bold 12px Vazirmatn')")
        if not fonts_ok:
            raise RuntimeError("Persian fonts did not load")
        layout = await page.locator(".page").evaluate_all("""pages => pages.map((p,i)=>{
          const footer=p.querySelector('.footer').getBoundingClientRect();
          const content=[...p.children].filter(c=>!c.classList.contains('footer'));
          return {page:i+1, bottom:Math.max(...content.map(c=>c.getBoundingClientRect().bottom)),
            footer:footer.top, horizontal:p.scrollWidth-p.clientWidth};
        })""")
        overflow = [info for info in layout if info["bottom"] > info["footer"] - 8 or info["horizontal"] > 1]
        if overflow:
            raise RuntimeError("Page content overlaps footer or overflows: " + json.dumps(overflow))
        await page.pdf(path=str(output), prefer_css_page_size=True, print_background=True,
                       display_header_footer=False, tagged=True, outline=True)
        await browser.close()

    preview = Path(os.environ.get("PDF_PREVIEW_DIR", str(ROOT / ".preview")))
    preview.mkdir(parents=True, exist_ok=True)
    with pymupdf.open(output) as document:
        if document.page_count != 7:
            raise RuntimeError(f"Expected 7 pages; got {document.page_count}")
        embedded = set()
        for number, pdf_page in enumerate(document, 1):
            if abs(pdf_page.rect.width - 595.28) > 2 or abs(pdf_page.rect.height - 841.89) > 2:
                raise RuntimeError("Unexpected page size")
            pdf_page.get_pixmap(matrix=pymupdf.Matrix(1.25, 1.25)).save(preview / f"page-{number}.png")
            embedded.update(font[3] for font in pdf_page.get_fonts())
        if not any("Vazirmatn" in name for name in embedded):
            raise RuntimeError("Vazirmatn is not embedded in the PDF")
        print(json.dumps({"pdf": str(output), "pages": document.page_count,
                          "fonts": sorted(embedded), "bytes": output.stat().st_size,
                          "preview": str(preview), "layout": layout}, ensure_ascii=False))

if __name__ == "__main__":
    asyncio.run(main())
