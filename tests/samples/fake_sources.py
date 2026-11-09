"""Local stand-in for download.blender.org and archive.org (tests only)."""
import json, os, sys, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VIDEO = os.urandom(1_200_000)
SEARCH = {"Night of the Living Dead": ["nld_trailer", "night_of_the_living_dead"]}
META = {
    "nld_trailer": [{"name": "trailer.mp4", "length": "150.0", "size": "9000000"}],
    "night_of_the_living_dead": [
        {"name": "nld_512kb.mp4", "length": "01:35:30", "size": "400000000"},
        {"name": "nld HQ.mp4", "length": "5730.5", "size": "900000000"},
        {"name": "nld.ogv", "length": "5730.5", "size": "990000000"},
    ],
}
FILES = {"/blender/peach/bigbuckbunny_movies/big_buck_bunny_1080p_h264.mov", "/archive/download/night_of_the_living_dead/nld%20HQ.mp4"}


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        sys.stderr.write(self.command + " " + self.path + "\n")

    def send(self, code, body=b"", ctype="application/octet-stream"):
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        u = urllib.parse.urlsplit(self.path)
        if u.path == "/archive/advancedsearch.php":
            q = urllib.parse.parse_qs(u.query)["q"][0]
            ids = next((v for k, v in SEARCH.items() if f'"{k}"' in q), [])
            return self.send(200, json.dumps({"response": {"docs": [{"identifier": i} for i in ids]}}).encode(), "application/json")
        if u.path.startswith("/archive/metadata/"):
            return self.send(200, json.dumps({"files": META.get(u.path.rsplit("/", 1)[1], [])}).encode(), "application/json")
        if self.path in FILES:
            return self.send(200, VIDEO)
        self.send(404)


ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1])), H).serve_forever()
