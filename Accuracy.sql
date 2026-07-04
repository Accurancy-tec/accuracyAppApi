create database if not exists Accurancy;
use Accurancy;

create table if not exists tb_aporte(
id_aporte int unsigned auto_increment,
ativo_aporte varchar(30) not null,
preco_aporte numeric(5) not null,
tipo_aporte varchar(30) not null,
recorrencia_aporte varchar(30) not null,
primary key(id_aporte)
)engine=InnoDB;

select * from tb_aporte;