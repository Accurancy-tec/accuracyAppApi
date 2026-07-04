CREATE DATABASE IF NOT EXISTS accurancy_db;
USE accurancy_db;

CREATE TABLE IF NOT EXISTS usuarios_info(
id_usuario int auto_increment,
nome_usuario varchar(100) not null,
email_usuario varchar(100) not null,
senha_usuario varchar(20) not null,
cpf_usuario varchar(14) not null unique,
telefone_usuario varchar(15) not null unique,
primary key(id_usuario)
);

drop table usuarios_info;

select * from usuarios_info;