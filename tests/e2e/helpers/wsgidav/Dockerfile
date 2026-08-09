FROM python:3.12-slim
RUN pip install --no-cache-dir wsgidav cheroot
RUN mkdir -p /data
COPY wsgidav.yaml /wsgidav.yaml
ENTRYPOINT ["wsgidav", "--config", "/wsgidav.yaml"]
