# Endpoints list for Role

- [List of all product](#1-list-of-all-product)
- [Get role by token](#2-get-role-by-token)
- [Purchase product](#3-purchase-product)
- [Get user order](#4-user-order-by-id)

## 1. List of all product
### To get all products
### Security: PUBLIC
### Method: GET
```
/api/v1/product
```
### Available get parameters
```shell
int `page` default 1
int `limit` default 10
array category_id default [] (example category_id[]=1&category_id[]=2) 
string full_text_search default null
int price_from default null
int price_to default null
```

### Response:
```json
{
  "data": [
    {
      "id": 1,
      "product_name": "fff",
      "product_properties": [
        {
          "property_name": "ggg",
          "property_value": "rrr"
        }
      ],
      "price": "222",
      "categories_info": [
        {
          "id": 1,
          "name": "Благоустрій"
        }
      ],
      "count_purchase": 0
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "to": 1,
    "per_page": 10,
    "total": 1
  },
  "links": {
    "first": "https://shuba-chalova-26-2.tplinkdns.com/api/v1/product?page=1",
    "last": "https://shuba-chalova-26-2.tplinkdns.com/api/v1/product?page=1"
  }
}
```

## 2. Get product by {id}
### Security: PUBLIC
### Method: GET
```
/api/v1/product/view/{id}
```
### Response:
```json
{
  "id": 1,
  "product_name": "fff",
  "product_properties": [
    {
      "property_name": "ggg",
      "property_value": "rrr"
    }
  ],
  "price": "222",
  "categories_info": [
    {
      "id": 1,
      "name": "Благоустрій"
    }
  ],
  "count_purchase": 0
}
```

## 3 Purchase product
### To purchase product by user only for USER_ROLE
### Security: Bearer {TOKEN}
### Method: POST
```
/api/v1/product/purchase/1
```
### Payload:
```json
{
  "quantity": 1
}
```
### Response:
```json
{
  "order": {
    "id": 4,
    "total_amount": "222",
    "description": "Ваше замовлення: fff: в кількості: 1 одиниць",
    "quantity_product": "1",
    "liq_pay_status": null,
    "liq_pay_response":"secure_data",
    "liq_pay_order_id": "4-1729258015",
    "telegram_user_id": null,
    "client_user_id": {
      "id": 2,
      "uuid": "01929f75-64a6-76c6-8491-78299688d4c0",
      "email": "q@q.com"
    },
    "product_id": {
      "id": 1,
      "product_name": "fff"
    }
  },
  "liqpay": {
    "result": "ok",
    "action": "pay",
    "amount": 222,
    "answer_text": "Privat24",
    "bot_channel": "Privat24",
    "bot_in_contacts": false,
    "bot_name": "Privatbank",
    "bot_url": "Privat24",
    "currency": "UAH",
    "description": "Ваше замовлення: fff: в кількості: 1 одиниць",
    "href": "secure_url_to_invoice",
    "id": 9789769,
    "order_id": "4-1729258015",
    "receiver_type": "phone",
    "receiver_value": "380123456789",
    "status": "invoice_wait",
    "token": "invoice_1729258015648222_54798019_QFz3PhC7RR2qbBM8tA26"
  },
  "link": "secure-link"
}
```

## 4 User order by id
### To user order only for USER_ROLE
### Security: Bearer {TOKEN}
### Method: POST

```
/api/v1/product/user-order/view/{id}
```

###
```json
{
    "id": 4,
    "total_amount": "222",
    "description": "Ваше замовлення: fff: в кількості: 1 одиниць",
    "quantity_product": "1",
    "liq_pay_status": null,
    "liq_pay_response":"secure-data",
    "liq_pay_order_id": "4-1729258015",
    "telegram_user_id": null,
    "client_user_id": {
        "id": 2,
        "uuid": "01929f75-64a6-76c6-8491-78299688d4c0",
        "email": "q@q.com"
    },
    "product_id": {
        "id": 1,
        "product_name": "fff"
    }
}
```