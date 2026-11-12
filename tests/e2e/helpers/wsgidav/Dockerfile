# Basisimage per Digest, pip-Pakete per Hash gepinnt (Scorecard
# Pinned-Dependencies, wie das Haupt-Dockerfile). Tag: python:3.12-slim
FROM python:3.12-slim@sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b
COPY requirements.txt /requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /requirements.txt
RUN mkdir -p /data
COPY wsgidav.yaml /wsgidav.yaml
ENTRYPOINT ["wsgidav", "--config", "/wsgidav.yaml"]
