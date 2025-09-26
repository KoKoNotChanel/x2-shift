# Dockerfile
FROM ubuntu:22.0

# Installer MySQL client
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-client && \
    rm -rf /var/lib/apt/lists/*

CMD [ "bash" ]
