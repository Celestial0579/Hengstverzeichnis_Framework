# FTPS-Backup-Ziel für den E2E-Nachtlauf. pyftpdlib (reiner Python-FTPS) statt
# pure-ftpd/vsftpd: pure-ftpd erzwingt TLS-Session-Reuse (PHPs ftp-Ext kann das
# nicht), vsftpd segfaultet in verschachtelter Containerisierung. Siehe
# lehren.json "backup-testserver-sehen-aus-wie-app-bugs".
FROM python:3.12-slim
RUN pip install --no-cache-dir pyftpdlib pyOpenSSL
RUN mkdir -p /cert /home/testftp/backups \
    && openssl req -x509 -newkey rsa:2048 -keyout /cert/cert.key -out /cert/cert.pem \
       -days 3650 -nodes -subj "/CN=e2e-ftps"
COPY server.py /server.py
ENTRYPOINT ["python", "/server.py"]
