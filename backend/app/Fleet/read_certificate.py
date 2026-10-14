import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

from pypdf import PdfReader


def read_certificate(path):
    reader = PdfReader(path)
    if reader.is_encrypted or len(reader.pages) > 8:
        return {"pages": [], "method": "pending"}
    pages = [page.extract_text() or "" for page in reader.pages]
    if all(len(text.strip()) > 80 for text in pages):
        return {"pages": pages, "method": "pdf_text"}
    import pypdfium2

    with tempfile.TemporaryDirectory(prefix="fleet-certificate-") as directory:
        document = pypdfium2.PdfDocument(path)
        images = []
        for i, page in enumerate(document):
            image = page.render(scale=min(2, 1900 / max(page.get_size()))).to_pil()
            image_path = Path(directory) / f"page-{i}.png"
            image.save(image_path)
            images.append(str(image_path))
        if os.name == "nt":
            executable = str(Path(os.environ.get("SystemRoot", "C:/Windows")) / "System32/WindowsPowerShell/v1.0/powershell.exe")
            command = [executable, "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", str(Path(__file__).with_name("ocr_certificate.ps1")), "-ImagePaths", ",".join(images)]
            result = subprocess.run(command, capture_output=True, timeout=35)
            if result.returncode:
                return {"pages": pages, "method": "pending"}
            scanned = json.loads(result.stdout.decode("utf-8-sig"))
            if isinstance(scanned, str):
                scanned = [scanned]
        else:
            scanned = []
            for image in images:
                result = subprocess.run(["tesseract", image, "stdout", "-l", "spa"], capture_output=True, timeout=15)
                scanned.append(result.stdout.decode("utf-8") if result.returncode == 0 else "")
        return {"pages": [text if len(text.strip()) > 80 else scanned[i] for i, text in enumerate(pages)], "method": "ocr"}


try:
    result = read_certificate(sys.argv[1])
except Exception:
    result = {"pages": [], "method": "pending"}
sys.stdout.buffer.write(json.dumps(result, ensure_ascii=False).encode("utf-8"))
