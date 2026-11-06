"""Local TLS server: working HTTP/1.1, deliberately broken HTTP/2 streams."""

import json
import ssl
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path


root = Path(sys.argv[1])
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(root / "cert.pem", root / "key.pem")
context.set_alpn_protocols(["h2", "http/1.1"])


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length", "0")))
        with (root / "requests.log").open("a") as trace:
            trace.write(self.request_version + " " + self.path + "\n")
        if self.path == "/upload":
            body = b"https://files.catbox.moe/fixture.jpg"
        else:
            result = {"access_token": "A" * 64} if self.path == "/createAccount" else {"url": "https://telegra.ph/fixture-11-06"}
            body = json.dumps({"ok": True, "result": result}).encode()
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class Server(HTTPServer):
    def get_request(self):
        raw, address = super().get_request()
        try:
            raw.settimeout(5)
            connection = context.wrap_socket(raw, server_side=True)
            with (root / "alpn.log").open("a") as trace:
                trace.write(str(connection.selected_alpn_protocol()) + "\n")
            if connection.selected_alpn_protocol() != "h2":
                return connection, address

            def read(size):
                data = b""
                while len(data) < size:
                    chunk = connection.recv(size - len(data))
                    if not chunk:
                        raise OSError("closed HTTP/2 fixture connection")
                    data += chunk
                return data

            connection.sendall(b"\x00\x00\x00\x04\x00\x00\x00\x00\x00")
            read(24)  # HTTP/2 client connection preface
            while True:
                header = read(9)
                read(int.from_bytes(header[:3], "big"))
                if header[3] == 1 and int.from_bytes(header[5:], "big") == 1:
                    break
            # RST_STREAM(INTERNAL_ERROR) reproduces libcurl's stream error 92.
            connection.sendall(b"\x00\x00\x04\x03\x00\x00\x00\x00\x01\x00\x00\x00\x02")
            try:
                while connection.recv(4096):
                    pass
            except OSError:
                pass
            connection.close()
            raise OSError("intentional HTTP/2 stream failure")
        except Exception as error:
            with (root / "alpn.log").open("a") as trace:
                trace.write(type(error).__name__ + ": " + str(error) + "\n")
            raw.close()
            raise


server = Server(("127.0.0.1", 0), Handler)
(root / "port").write_text(str(server.server_port))
server.serve_forever()
