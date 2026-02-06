
# ENVNAME
#   prod - конфигурация "Продакшин", приложение смотрит на боевой сервер.
#   dev* - конфигурация для разработки, запускаются база и пгадмин 

APPNAME=stat
TAG=1.618

function dev()
{
	export ENVNAME=dev
	CI_URL="stat.mcn.local"
  PGADMIN_IN_DEV="yes"
  export CI_DIR_HOME="/home/httpd/stat.mcn.ru"
  export COUNTRY="RU"
  export IS_MINIKUBE=1
  export IS_WITH_CRON=1

  export IS_WITH_PHPMYADMIN=1
  export IS_WITH_PGADMIN=1
  export IS_WITH_CRYPTOPRO=0
  export IS_WITH_COMET=0
  export IS_WITH_GRAPHQL=0
  export IS_WITH_NNPPORTED=0
  export IS_WITH_BALANCE=0
  export IS_WITH_MAILER=0
}

function eudev()
{
	export ENVNAME=dev
	CI_URL="stat.mcntelecom.local"
  PGADMIN_IN_DEV="yes"
  export CI_DIR_HOME="/home/httpd/stat.mcn.ru"
  export COUNTRY="EU"
  export IS_MINIKUBE=1
  export IS_WITH_CRON=0

  export IS_WITH_PHPMYADMIN=1
  export IS_WITH_PGADMIN=1
  export IS_WITH_CRYPTOPRO=0
  export IS_WITH_COMET=0
  export IS_WITH_GRAPHQL=0
  export IS_WITH_NNPPORTED=0
  export IS_WITH_BALANCE=0
  export IS_WITH_MAILER=0
}

function prod()
{
	export ENVNAME=prod
	CI_URL="stat.mcn.ru"
	export CI_DIR_HOME="/home/httpd/stat.mcn.ru"
	export COUNTRY="RU"
	export IS_MINIKUBE=0
	export IS_WITH_CRON=0

  export IS_WITH_PHPMYADMIN=0
  export IS_WITH_PGADMIN=0
  export IS_WITH_CRYPTOPRO=0
  export IS_WITH_COMET=0
  export IS_WITH_GRAPHQL=0
  export IS_WITH_NNPPORTED=1
  export IS_WITH_BALANCE=0
  export IS_WITH_MAILER=1

  if [[ "$IS_MINIKUBE" == 1 ]]; then
    CI_URL="${CI_URL}.local"
  fi
}

function euprod()
{
	export ENVNAME=prod
	CI_URL="stat.kompaas.tech"
	export CI_DIR_HOME="/home/httpd/stat.mcn.ru"
	export COUNTRY="EU"
	export IS_MINIKUBE=0
	export IS_WITH_CRON=1

  export IS_WITH_PHPMYADMIN=0
  export IS_WITH_PGADMIN=0
  export IS_WITH_CRYPTOPRO=0
  export IS_WITH_COMET=1
  export IS_WITH_GRAPHQL=0
  export IS_WITH_NNPPORTED=0
  export IS_WITH_BALANCE=0
  export IS_WITH_MAILER=1

  if [[ "$IS_MINIKUBE" == 1 ]]; then
    CI_URL="${CI_URL}.local"
  fi
}

function stage2()
{
	export ENVNAME=stage
	CI_URL="stat.mcnloc.ru"
	export CI_DIR_HOME="/home/httpd/stat.mcn.ru"
	export COUNTRY="RU"
	export IS_MINIKUBE=0
	export IS_WITH_CRON=1

  export IS_WITH_PHPMYADMIN=0
  export IS_WITH_PGADMIN=0
  export IS_WITH_CRYPTOPRO=0
  export IS_WITH_COMET=1
  export IS_WITH_GRAPHQL=0
  export IS_WITH_NNPPORTED=0
  export IS_WITH_BALANCE=0
  export IS_WITH_MAILER=1

  if [[ "$IS_MINIKUBE" == 1 ]]; then
    CI_URL="${CI_URL}.local"
  fi
}

