# FTPS-Backup-Ziel für den E2E-Nachtlauf. pyftpdlib (reiner Python-FTPS) statt
# pure-ftpd/vsftpd: pure-ftpd erzwingt TLS-Session-Reuse (PHPs ftp-Ext kann das
# nicht), vsftpd segfaultet in verschachtelter Containerisierung. Siehe
# lehren.json "backup-testserver-sehen-aus-wie-app-bugs".
# Basisimage per Digest, pip-Pakete per Hash gepinnt (Scorecard
# Pinned-Dependencies, wie das Haupt-Dockerfile). Tag: python:3.12-slim
FROM python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b
COPY requirements.txt /requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /requirements.txt
RUN mkdir -p /cert /home/testftp/backups \
    && openssl req -x509 -newkey rsa:2048 -keyout /cert/cert.key -out /cert/cert.pem \
       -days 3650 -nodes -subj "/CN=e2e-ftps"
COPY server.py /server.py
ENTRYPOINT ["python", "/server.py"]
