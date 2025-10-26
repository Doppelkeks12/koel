
# Install pnpm
USER $username
RUN wget -qO- https://get.pnpm.io/install.sh | ENV="$HOME/.bashrc" SHELL="$(which bash)" bash -
ENV PNPM_HOME="$HOME/.local/share/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
USER root
