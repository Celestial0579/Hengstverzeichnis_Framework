import os
from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import TLS_FTPHandler
from pyftpdlib.servers import FTPServer
os.makedirs("/home/testftp/backups", exist_ok=True)
auth = DummyAuthorizer()
auth.add_user("testftp", os.environ.get("FTP_USER_PASS", "ftps-e2e-testpass"),
              "/home/testftp", perm="elradfmwMT")
h = TLS_FTPHandler
h.certfile = "/cert/cert.pem"; h.keyfile = "/cert/cert.key"
h.authorizer = auth
h.tls_control_required = True; h.tls_data_required = True
h.passive_ports = range(30000, 30010)
# Nur setzen, wenn ausdrücklich gewünscht (Host-Zugriff über 127.0.0.1). Im
# Container-zu-Container-Fall NICHT setzen -> pyftpdlib nutzt die echte lokale
# Adresse der Kontrollverbindung, die der Client (App-Container) erreichen kann.
if os.environ.get("PASV_ADDRESS"):
    h.masquerade_address = os.environ["PASV_ADDRESS"]
FTPServer(("0.0.0.0", 21), h).serve_forever()
