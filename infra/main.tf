# Everything is named "pluginxchange" because deploy/deploy.sh reads its secrets from /pluginxchange/* in SSM.
locals {
  name         = "pluginxchange"
  site_address = var.domain != "" ? var.domain : ":80"
}

data "aws_caller_identity" "current" {}

# The account's default VPC keeps this small: one public subnet is all a single instance needs.
data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }

  filter {
    name   = "default-for-az"
    values = ["true"]
  }
}

data "aws_ssm_parameter" "al2023_ami" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-x86_64"
}
