<?php

ini_set('memory_limit', '1G');

require __DIR__ . '/src/gc/GameChat.php';

$gc = new GameChat();

//Путь к файлу лога чата сервера
$gc->set_chat_file("chat.log");

//Пароль для админаского просмотра чата (пароль от 32 символов)
$gc->setPassword("(2kS8!@Jruj2(@!@Jruj2(@!@Jruj2(@!@Jruj2(@1");

//В игре шифтуются в чат предметы
$gc->setItemShiftShow(true);

//Адрес сокета
$gc->address("tcp://localhost:17859");

//Запуск скрипта
$gc->start();
