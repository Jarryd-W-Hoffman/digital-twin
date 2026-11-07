#!/bin/sh
# Creates each bucket in $RUSTFS_BUCKETS in RustFS, letting the app's origin
# ($RUSTFS_CORS_ORIGINS) PUT direct upload parts and read their ETags.
# Idempotent.
set -e

for bucket in $RUSTFS_BUCKETS; do
    if aws --endpoint-url "$AWS_ENDPOINT" s3api head-bucket --bucket "$bucket" >/dev/null 2>&1; then
        echo "Bucket $bucket already exists"
    else
        aws --endpoint-url "$AWS_ENDPOINT" s3api create-bucket --bucket "$bucket"
        echo "Created bucket $bucket"
    fi

    if [ -n "$RUSTFS_CORS_ORIGINS" ]; then
        origins=$(printf '"%s",' $RUSTFS_CORS_ORIGINS)
        aws --endpoint-url "$AWS_ENDPOINT" s3api put-bucket-cors --bucket "$bucket" --cors-configuration \
            "{\"CORSRules\":[{\"AllowedOrigins\":[${origins%,}],\"AllowedMethods\":[\"PUT\"],\"AllowedHeaders\":[\"*\"],\"ExposeHeaders\":[\"ETag\"],\"MaxAgeSeconds\":3600}]}"
        echo "Allowed direct uploads to $bucket from $RUSTFS_CORS_ORIGINS"
    fi
done
