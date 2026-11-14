# One private bucket: deploy files written by the release workflow (deploy/) and nightly database dumps (dumps/).
resource "random_id" "bucket" {
  byte_length = 4
}

# Accepted findings:
#   AWS-0089 access logging: one private bucket written by two known roles, so the logs would only add cost and noise.
#   AWS-0090 versioning: dumps are written once under a dated name and expire after 30 days, deploy files are
#            overwritten on purpose by each release. Old versions would only pile up.
#   AWS-0132 SSE-S3 (AES256) instead of a customer managed KMS key, see the ECR note above.
#trivy:ignore:AWS-0089
#trivy:ignore:AWS-0090
resource "aws_s3_bucket" "data" {
  bucket        = "${local.name}-data-${random_id.bucket.hex}"
  force_destroy = true # lets "terraform destroy" remove the bucket even if it holds dumps
}

resource "aws_s3_bucket_public_access_block" "data" {
  bucket                  = aws_s3_bucket.data.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

#trivy:ignore:AWS-0132
resource "aws_s3_bucket_server_side_encryption_configuration" "data" {
  bucket = aws_s3_bucket.data.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

# Old dumps expire so the bucket does not grow forever
resource "aws_s3_bucket_lifecycle_configuration" "data" {
  bucket = aws_s3_bucket.data.id

  rule {
    id     = "expire-old-dumps"
    status = "Enabled"

    filter {
      prefix = "dumps/"
    }

    expiration {
      days = 30
    }

    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

data "aws_iam_policy_document" "bucket_tls_only" {
  statement {
    sid       = "DenyInsecureTransport"
    effect    = "Deny"
    actions   = ["s3:*"]
    resources = [aws_s3_bucket.data.arn, "${aws_s3_bucket.data.arn}/*"]

    principals {
      type        = "*"
      identifiers = ["*"]
    }

    condition {
      test     = "Bool"
      variable = "aws:SecureTransport"
      values   = ["false"]
    }
  }
}

resource "aws_s3_bucket_policy" "data" {
  bucket = aws_s3_bucket.data.id
  policy = data.aws_iam_policy_document.bucket_tls_only.json

  depends_on = [aws_s3_bucket_public_access_block.data]
}
