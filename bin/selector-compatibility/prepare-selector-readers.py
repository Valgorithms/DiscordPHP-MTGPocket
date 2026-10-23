"""Extract immutable historical readers; never use a text-mode shell pipeline.

Preparation only: this does not invoke PHP, run tests, or access the network.
"""
import hashlib
import json
from pathlib import Path
import subprocess

repo = Path(__file__).resolve().parents[2]
root = repo / "var" / "selector-compatibility"
readers = {
    "main": "710a47cde58525c24d59fb591ef81b6d63ee945d",
    "pr331": "3f309578ebc35f17ae9321f969a67fe4e14db4ab",
}
manifest = {}
for label, revision in readers.items():
    directory = root / "selector-readers" / label
    directory.mkdir(parents=True, exist_ok=True)
    files = {}
    for name in ("Game.php", "TextParser.php"):
        source = f"src/MTGPocket/Game/{name}"
        blob = subprocess.check_output(
            ["git", "-c", f"safe.directory={repo.as_posix()}", "rev-parse", f"{revision}:{source}"], cwd=repo
        ).decode("ascii").strip()
        data = subprocess.check_output(["git", "-c", f"safe.directory={repo.as_posix()}", "cat-file", "blob", blob], cwd=repo)
        (directory / name).write_bytes(data)
        files[name] = {"gitBlob": blob, "sha256": hashlib.sha256(data).hexdigest()}
    manifest[label] = {"commit": revision, "files": files}
(root / "selector-readers" / "manifest.json").write_text(
    json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
)
print(json.dumps(manifest, indent=2))
