#!/bin/sh
set -e

CUR_DIR=$(dirname $(realpath $0))
BASE_DIR=$(dirname "$CUR_DIR")

echo "Building testable image"
docker build "$BASE_DIR" --target testable --tag db-dump-anonymizer:testable --build-arg UID=$(id -u) --build-arg GID=$(id -g)

echo "Image built."
echo "Example of usage: $ docker run db-dump-anonymizer:testable"

echo "Building runnable image"
docker build "$BASE_DIR" --target runnable --tag db-dump-anonymizer:runnable --build-arg UID=$(id -u) --build-arg GID=$(id -g)

echo "Image built."
echo "Example of usage: $ docker run db-dump-anonymizer:runnable [OPTIONS]"
