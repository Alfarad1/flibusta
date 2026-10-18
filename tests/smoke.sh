#!/usr/bin/env bash
# Smoke-тест работающего сайта: основные страницы, вход, избранное, OPDS.
# Использование: tests/smoke.sh [http://127.0.0.1:27100]
# Создаёт временного пользователя и в конце удаляет его.
set -uo pipefail
B=${1:-http://127.0.0.1:27100}
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
JAR=$T/cookies
fails=0

ok() { echo "ok   $1"; }
fail() { echo "FAIL $1"; fails=$((fails + 1)); }
# check "описание" условие...
check() { local d=$1; shift; if "$@"; then ok "$d"; else fail "$d"; fi; }

get() { curl -s -b "$JAR" -c "$JAR" "$@"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
has() { grep -q -- "$2" "$1"; }

for p in / /genres/ /authors/ /series/ /users/ /fav/ /service/ /opds/ /opds/favs/; do
	check "GET $p — 200" [ "$(code "$B$p")" = 200 ]
done
check "старый адрес /favlist/ ведёт на /users/" \
	sh -c "curl -s -o /dev/null -w '%{redirect_url}' '$B/favlist/' | grep -q '/users/'"

get "$B/" >"$T/home"
check "без входа в меню «Войти»" has "$T/home" 'Войти'
book=$(grep -o "data-fav-id='[0-9]*'" "$T/home" | head -1 | grep -o '[0-9]*')
book=${book:-$(grep -o "/book/view/[0-9]*" "$T/home" | head -1 | grep -o '[0-9]*')}
check "на главной есть книги" [ -n "$book" ]

check "избранное без входа — 403" [ "$(code -X POST -d "type=book&id=$book&on=1" "$B/fav.php")" = 403 ]
check "позиция чтения без входа — 403" [ "$(code -X POST -d "bookid=$book&pos=10" "$B/save_position.php")" = 403 ]

name="Smoke $RANDOM <b>"
check "создание пользователя — редирект" \
	[ "$(code -X POST --data-urlencode "action=create" --data-urlencode "name=$name" "$B/users/")" = 302 ]
check "после создания выдана cookie" grep -q flibusta_user "$JAR"
uuid=$(awk '$6 == "flibusta_user" {print $7}' "$JAR")

get "$B/" >"$T/home"
check "имя в меню экранировано" has "$T/home" 'Smoke [0-9]* &lt;b&gt;'
check "повторное имя занято" sh -c "curl -s -X POST --data-urlencode 'action=create' --data-urlencode 'name=$name' '$B/users/' | grep -q 'уже занято'"

check "в избранное" sh -c "curl -s -b '$JAR' -X POST -d 'type=book&id=$book&on=1' '$B/fav.php' | grep -q '\"fav\":true'"
get "$B/fav/" >"$T/fav"
check "книга на полке" has "$T/fav" "data-fav-id='$book'"
check "позиция чтения сохраняется" [ "$(code -X POST -d "bookid=$book&pos=10" "$B/save_position.php")" = 200 ]

get "$B/opds/favs/" >"$T/favs"
check "OPDS: пользователь в списке полок" has "$T/favs" "$uuid"
check "OPDS: имя экранировано" has "$T/favs" '&lt;b&gt;'
get "$B/opds/fav/?uuid=$uuid" >"$T/opdsfav"
check "OPDS: книга в ленте полки" has "$T/opdsfav" "tag:book:$book"
check "OPDS: мусор вместо uuid не ломает ленту" [ "$(code "$B/opds/fav/?uuid=x'")" = 200 ]

check "переименование" [ "$(code -X POST --data-urlencode "action=rename" --data-urlencode "name=$name 2" "$B/users/")" = 302 ]
check "удаление без подтверждения не проходит" sh -c "curl -s -b '$JAR' -X POST -d 'action=delete&confirm=x' '$B/users/' | grep -q 'введите своё имя'"
check "удаление" [ "$(code -X POST --data-urlencode "action=delete" --data-urlencode "confirm=$name 2" "$B/users/")" = 302 ]
check "после удаления пользователя нет" sh -c "! curl -s '$B/users/' | grep -q '$uuid'"
get "$B/" >"$T/home"
check "после удаления снова «Войти»" has "$T/home" 'Войти'

echo
if [ $fails -gt 0 ]; then
	echo "Ошибок: $fails"
	exit 1
fi
echo "Все проверки пройдены"
