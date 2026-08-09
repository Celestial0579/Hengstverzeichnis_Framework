# FTPS-Backup-Ziel für den E2E-Nachtlauf. pyftpdlib (reiner Python-FTPS) statt
# pure-ftpd/vsftpd: pure-ftpd erzwingt TLS-Session-Reuse (PHPs ftp-Ext kann das
# nicht), vsftpd segfaultet in verschachtelter Containerisierung. Siehe
# lehren.json "backup-testserver-sehen-aus-wie-app-bugs".
# Basisimage per Digest, pip-Pakete per Hash gepinnt (Scorecard
# Pinned-Dependencies, wie das Haupt-Dockerfile). Tag: python:3.12-slim
FROM python:3.12-slim@sha256:229a2c5bfa27522db7815ea81f9bed70af17ccb9de9fc7ad142b1877b5830d36
COPY requirements.txt /requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /requirements.txt
RUN mkdir -p /cert /home/testftp/backups \
    && openssl req -x509 -newkey rsa:2048 -keyout /cert/cert.key -out /cert/cert.pem \
       -days 3650 -nodes -subj "/CN=e2e-ftps"
COPY server.py /server.py
ENTRYPOINT ["python", "/server.py"]
