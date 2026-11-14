# Accepted findings:
#   AWS-0031 mutable tags: re-running an old release workflow (the rollback path) pushes the same tag again, which an
#            immutable repository would reject. Release tags are only ever moved by that re-run.
#   AWS-0033 AWS-managed AES256 encryption is enough here, a customer managed KMS key costs extra and adds nothing for a demo.
#trivy:ignore:AWS-0031
#trivy:ignore:AWS-0033
resource "aws_ecr_repository" "app" {
  name                 = local.name
  image_tag_mutability = "MUTABLE" # a re-run of a release workflow pushes the same tag again
  force_delete         = true      # lets "terraform destroy" remove the repository even if it holds images

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "AES256"
  }
}

# Keep storage small: only the newest images are kept (enough to roll back a few releases)
resource "aws_ecr_lifecycle_policy" "app" {
  repository = aws_ecr_repository.app.name

  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "Keep the newest 15 images"
      selection = {
        tagStatus   = "any"
        countType   = "imageCountMoreThan"
        countNumber = 15
      }
      action = { type = "expire" }
    }]
  })
}
