# MicDog Finance Tracker (PHP + HTML/JS)

Rastreador financeiro pessoal completo e pronto para uso, criado para o MicDog.
Inclui:
- Cadastro de receitas e despesas
- Filtros por período, categoria e busca
- Resumo com receitas, despesas e saldo
- Gráfico mensal (Chart.js)
- Exportação e importação CSV
- API REST em PHP com proteção CSRF
- Banco SQLite (portável, sem dependências externas)
- Frontend HTML/JS/CSS leve

## Requisitos
- PHP 8.1+ com PDO SQLite habilitado
- Navegador moderno

## Como rodar (servidor embutido do PHP)
No diretório do projeto, execute:

```bash
php -S localhost:8080 -t .
```

Depois acesse o frontend em:
```
http://localhost:8080/public/
```
A API estará disponível em:
```
http://localhost:8080/api/
```

Ao primeiro uso, o backend cria automaticamente:
- A pasta `data/` (se não existir)
- O arquivo `data/finance.sqlite`
- As tabelas necessárias

## API (resumo)
Para todas as requisições não-GET, envie o header `X-CSRF-Token`, obtido em `GET /api/csrf`.

- `GET /api/transactions?from=YYYY-MM-DD&to=YYYY-MM-DD&category=...&q=...`
- `POST /api/transactions`  (JSON: `{date, description, category, account, amount, tags}`)
- `PUT /api/transactions/{id}`
- `DELETE /api/transactions/{id}`
- `GET /api/stats?from=...&to=...`  (totais e séries por mês e por categoria)
- `GET /api/export?...`  (CSV)
- `POST /api/import`  (form-data: `file` com colunas: `date,description,category,account,amount,tags`)

## CSV (formato)
Cabeçalho esperado:
```
date,description,category,account,amount,tags
2025-08-01,Salário,Salário,Conta Corrente,6500.00,
2025-08-02,Aluguel,Moradia,Conta Corrente,-1800.00,
```

## Segurança
- CSRF por sessão
- Prepared statements (PDO) em todas as operações

## Estrutura
```
micdog-finance-tracker-php/
├─ README.md
├─ .gitignore
├─ data/                  # criado em tempo de execução
├─ api/
│  └─ index.php
└─ public/
   ├─ index.html
   ├─ app.css
   └─ app.js
```

## Licença
MIT
