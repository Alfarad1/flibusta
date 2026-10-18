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
favshelf=$(grep -o "/fav/shelf/[0-9]*" "$T/fav" | head -1 | grep -o '[0-9]*$')
check "у нового пользователя есть «Избранное»" [ -n "$favshelf" ]
get "$B/fav/shelf/$favshelf" >"$T/shelf"
check "книга на полке «Избранное»" has "$T/shelf" "data-fav-id='$book'"
check "читалка открывается" [ "$(code "$B/read/$book")" = 200 ]
check "читалка: нет книги — 404" [ "$(code "$B/read/999999999")" = 404 ]
check "позиция чтения сохраняется" [ "$(code -X POST -d "bookid=$book&pos=10.5&loc=p:12:0.250" "$B/save_position.php")" = 200 ]
check "после чтения статус «Читаю»" sh -c "curl -s -b '$JAR' '$B/fav/status/2' | grep -q '/book/view/$book'"
check "на странице книги «Продолжить · 10%»" sh -c "curl -s -b '$JAR' '$B/book/view/$book' | grep -q 'Продолжить чтение · 10%' || ! curl -s -b '$JAR' '$B/book/view/$book' | grep -q '/read/$book'"
check "позиция передаётся в читалку" sh -c "curl -s -b '$JAR' '$B/read/$book' | grep -q 'Файл\\|p:12:0.250'"

check "создание полки" [ "$(code -X POST --data-urlencode "action=create" --data-urlencode "name=Полка <i>" "$B/fav/")" = 302 ]
shelf=$(get "$B/fav/" | grep -o "/fav/shelf/[0-9]*" | grep -v "/$favshelf\$" | head -1 | grep -o '[0-9]*$')
check "новая полка в списке" [ -n "$shelf" ] && [ "$shelf" != "$favshelf" ]
check "название полки экранировано" sh -c "curl -s -b '$JAR' '$B/fav/' | grep -q 'Полка &lt;i&gt;'"
check "повторное название полки занято" sh -c "curl -s -b '$JAR' -X POST --data-urlencode 'action=create' --data-urlencode 'name=полка <I>' '$B/fav/' | grep -q 'уже есть'"
check "книга на свою полку" sh -c "curl -s -b '$JAR' -X POST -d 'bookid=$book&shelf=$shelf&on=1' '$B/mark.php' | grep -Eq '\"shelves\":\[([0-9]+,)*$shelf[],]'"
check "статус «Читаю»" sh -c "curl -s -b '$JAR' -X POST -d 'bookid=$book&status=2' '$B/mark.php' | grep -q '\"status\":2'"
JAR2=$T/cookies2
name2="Smoke2 $RANDOM"
curl -s -o /dev/null -c "$JAR2" -X POST --data-urlencode "action=create" --data-urlencode "name=$name2" "$B/users/"
shelf2=$(curl -s -b "$JAR2" "$B/fav/" | grep -o "/fav/shelf/[0-9]*" | head -1 | grep -o '[0-9]*$')
check "чужую полку менять нельзя" [ "$(code -X POST -d "bookid=$book&shelf=$shelf2&on=1" "$B/mark.php")" = 403 ]
curl -s -o /dev/null -b "$JAR2" -X POST --data-urlencode "action=delete" --data-urlencode "confirm=$name2" "$B/users/"
check "без входа отметки — 403" [ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d "bookid=$book&status=1" "$B/mark.php")" = 403 ]
get "$B/fav/shelf/$shelf" >"$T/shelf"
check "книга на новой полке" has "$T/shelf" "data-fav-id='$book'"
get "$B/fav/status/2" >"$T/status"
check "книга в списке «Читаю»" has "$T/status" "data-fav-id='$book'"
get "$B/" >"$T/home"
check "на главной «Продолжить чтение»" has "$T/home" "Продолжить чтение"
check "на странице книги статус" sh -c "curl -s -b '$JAR' '$B/book/view/$book' | grep -q 'fa-book-open'"
check "чужие полки видны без входа" sh -c "curl -s '$B/fav/shelf/$shelf' | grep -q '/book/view/$book'"
check "чужие полки без формы удаления" sh -c "! curl -s '$B/fav/shelf/$shelf' | grep -q 'Удалить полку'"
check "переименование полки" [ "$(code -X POST --data-urlencode "action=rename" --data-urlencode "name=Полка 2" "$B/fav/shelf/$shelf")" = 302 ]

get "$B/opds/favs/" >"$T/favs"
check "OPDS: пользователь в списке полок" has "$T/favs" "$uuid"
check "OPDS: имя экранировано" has "$T/favs" '&lt;b&gt;'
get "$B/opds/fav/?uuid=$uuid" >"$T/opdsfav"
check "OPDS: полки пользователя" has "$T/opdsfav" "shelf=$shelf"
get "$B/opds/fav/?uuid=$uuid&shelf=$favshelf" >"$T/opdsfav"
check "OPDS: книга в ленте «Избранного»" has "$T/opdsfav" "tag:book:$book"
get "$B/opds/fav/?uuid=$uuid&status=2" >"$T/opdsfav"
check "OPDS: книга в ленте «Читаю»" has "$T/opdsfav" "tag:book:$book"
check "OPDS: мусор вместо uuid не ломает ленту" [ "$(code "$B/opds/fav/?uuid=x'")" = 200 ]

check "переименование" [ "$(code -X POST --data-urlencode "action=rename" --data-urlencode "name=$name 2" "$B/users/")" = 302 ]
check "удаление без подтверждения не проходит" sh -c "curl -s -b '$JAR' -X POST -d 'action=delete&confirm=x' '$B/users/' | grep -q 'введите своё имя'"
check "удаление полки" [ "$(code -X POST -d "action=delete" "$B/fav/shelf/$shelf")" = 302 ]
check "полки больше нет" sh -c "curl -s '$B/fav/shelf/$shelf' | grep -q 'Такой полки нет'"
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
