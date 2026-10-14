# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Minimal self-hosted MCP harness: publishes its OAuth Client ID Metadata Document and receives the callback."""
import http.server, json, ssl, sys, datetime, urllib.parse

BASE = 'https://harness.test:8443'
DOC = {
    'client_id': BASE + '/client.json',
    'client_name': 'My Self-Hosted Harness',
    'redirect_uris': [BASE + '/oauth/callback'],
    'grant_types': ['authorization_code', 'refresh_token'],
    'response_types': ['code'],
    'token_endpoint_auth_method': 'none',
}
LOG = sys.argv[1]

class H(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        with open(LOG, 'a') as f:
            f.write(f"{datetime.datetime.now(datetime.timezone.utc).isoformat()} {self.client_address[0]} {self.command} {self.path} UA={self.headers.get('User-Agent','')}\n")

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        if path == '/client.json':
            body, ctype = json.dumps(DOC).encode(), 'application/json'
        elif path == '/oauth/callback':
            body, ctype = b'<h1>Harness callback received</h1>', 'text/html'
        else:
            self.send_response(404); self.end_headers(); return
        self.send_response(200)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(sys.argv[2], sys.argv[3])
srv = http.server.ThreadingHTTPServer(('0.0.0.0', 8443), H)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
