# Basisimage per Digest, pip-Pakete per Hash gepinnt (Scorecard
# Pinned-Dependencies, wie das Haupt-Dockerfile). Tag: python:3.12-slim
FROM python:3.12-slim@sha256:229a2c5bfa27522db7815ea81f9bed70af17ccb9de9fc7ad142b1877b5830d36
COPY requirements.txt /requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /requirements.txt
RUN mkdir -p /data
COPY wsgidav.yaml /wsgidav.yaml
ENTRYPOINT ["wsgidav", "--config", "/wsgidav.yaml"]
