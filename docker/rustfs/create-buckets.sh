#!/bin/sh
# Creates each bucket in $RUSTFS_BUCKETS in RustFS. Idempotent.
set -e

for bucket in $RUSTFS_BUCKETS; do
    if aws --endpoint-url "$AWS_ENDPOINT" s3api head-bucket --bucket "$bucket" >/dev/null 2>&1; then
        echo "Bucket $bucket already exists"
    else
        aws --endpoint-url "$AWS_ENDPOINT" s3api create-bucket --bucket "$bucket"
        echo "Created bucket $bucket"
    fi
done
