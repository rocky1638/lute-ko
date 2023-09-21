FROM php:8.2

# Install mecab for Japanese support
RUN apt-get update -y \
  && apt-get install -y mecab mecab-ipadic-utf8 \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

# Other tools
RUN apt-get update && apt-get install -y \
  git \
  curl \
  zip \
  unzip \
  wget

# Install Mecab-KO for Korean support
# Mecab-Ko isn't available on apt-get repositories so we wget the release from Github
RUN wget -4 -O - https://github.com/Pusnow/mecab-ko-msvc/releases/download/release-0.999/mecab-ko-linux-aarch64.tar.gz > mecab-ko-linux-aarch64.tar.gz
RUN tar -C /opt -xvzf mecab-ko-linux-aarch64.tar.gz
RUN wget -4 -O - https://github.com/Pusnow/mecab-ko-msvc/releases/download/release-0.999/mecab-ko-dic.tar.gz > mecab-ko-dic.tar.gz
RUN tar -C /opt/mecab/share -xvzf mecab-ko-dic.tar.gz

# Clean the tar files
RUN rm -rf mecab-ko-linux-aarch64.tar.gz mecab-ko-dic.tar.gz

ENV APP_ENV=prod

# Install Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
  && php -r "unlink('composer-setup.php');"

WORKDIR /lute

COPY ./composer.* ./

# Composer dependencies.
# --no-scripts was necessary because otherwise the build failed with:
#   Executing script cache:clear [KO]
#   Script cache:clear returned with error code 1
#   !!  Could not open input file: ./bin/console
RUN APP_ENV=prod composer install --no-dev --no-scripts

COPY . .
# COPY .env.example ./.env

WORKDIR public
CMD ["php", "-S", "0.0.0.0:8000"]
EXPOSE 8000