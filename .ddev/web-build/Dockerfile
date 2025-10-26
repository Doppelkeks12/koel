
# Install pnpm
USER $username
RUN wget -qO- https://get.pnpm.io/install.sh | ENV="$HOME/.bashrc" SHELL="$(which bash)" bash -
ENV PNPM_HOME="$HOME/.local/share/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
USER root
RUN apt-get update && \
    apt-get install -y pngquant zip unzip && \
    rm -rf /var/lib/apt/lists/*
