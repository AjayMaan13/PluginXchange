resource "aws_instance" "app" {
  ami                         = data.aws_ssm_parameter.al2023_ami.value
  instance_type               = var.instance_type
  subnet_id                   = data.aws_subnets.default.ids[0]
  vpc_security_group_ids      = [aws_security_group.web.id]
  iam_instance_profile        = aws_iam_instance_profile.app.name
  associate_public_ip_address = true
  user_data                   = templatefile("${path.module}/user_data.sh.tftpl", { compose_version = var.compose_version })
  user_data_replace_on_change = false

  # IMDSv2 only: a request that can reach the instance's credentials must first fetch a token
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  # The database lives in a Docker volume on this disk. The nightly S3 dumps are the recovery path.
  root_block_device {
    volume_type           = "gp3"
    volume_size           = var.disk_gb
    encrypted             = true
    delete_on_termination = true
  }

  tags = {
    Name = local.name
  }

  # A newer AMI or edited first-boot script must not replace the instance on a routine apply: that would wipe the database
  lifecycle {
    ignore_changes = [ami, user_data]
  }
}

# A fixed public IP, so the address survives a stop and start
resource "aws_eip" "app" {
  domain   = "vpc"
  instance = aws_instance.app.id

  tags = {
    Name = local.name
  }
}
