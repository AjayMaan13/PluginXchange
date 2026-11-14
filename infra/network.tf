# Web traffic in, nothing else. There is no SSH: shell access goes through SSM Session Manager.
resource "aws_security_group" "web" {
  name_prefix = "${local.name}-web-"
  description = "HTTP and HTTPS from anywhere; no SSH"
  vpc_id      = data.aws_vpc.default.id

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_vpc_security_group_ingress_rule" "http" {
  security_group_id = aws_security_group.web.id
  description       = "HTTP"
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
  cidr_ipv4         = "0.0.0.0/0"
}

resource "aws_vpc_security_group_ingress_rule" "https" {
  security_group_id = aws_security_group.web.id
  description       = "HTTPS"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
  cidr_ipv4         = "0.0.0.0/0"
}

# Accepted finding AWS-0104: the instance must reach ECR, S3, SSM, Docker Hub, GitHub and Let's Encrypt, none of which
# has a fixed address range. Restricting this would need paid VPC endpoints and proxies.
#trivy:ignore:AWS-0104
resource "aws_vpc_security_group_egress_rule" "all" {
  security_group_id = aws_security_group.web.id
  description       = "Outbound: image pulls, package installs, SSM, S3"
  ip_protocol       = "-1"
  cidr_ipv4         = "0.0.0.0/0"
}
