# Endpoints list for Checkout flow

- [Empty-shopping-cart](#1-show-user-shopping-cart)
- [Shopping-cart-unpurchase-products](#11-show-user-shopping-cart)
- [Add-product-to-shopping-cart](#2-add-product-to-shopping-cart)
- [Upadte-purchase-product](#3-update-purchase-product)
- [Shopping-cart-after-update-purchase-product](#31-show-user-shopping-cart-after-one-of-purchase-product-was-updated)
- [Shopping-cart-after-update-purchase-product](#32-show-user-shopping-cart-after-one-of-purchase-product-was-deleted)
- [Checkout](#4-checkout)
- [Shopping-cart-after-checkout](#41-show-user-shopping-cart-after-checkout)
- [User-order-after-checkout](#5-show-user-all-orders)

## 1. Show user shopping cart
### Show own user shopping cart
### Security: PRIVATE
### Method: GET
```
/api/v1/cart
```
### Response:
```json
{
  "id": 1,
  "purchasedProduct": [],
  "unpurchasedProduct": []
}
```

## 1.1 Show user shopping cart
### Show own user shopping cart with one/two un purchased product
### Security: PRIVATE
### Method: GET
```
/api/v1/cart
```
### Response:
```json
{
  "id": 1,
  "purchasedProduct": [],
  "unpurchasedProduct": [
    {
      "id": 2,
      "product_properties": [
        {
          "property_name": "Клас",
          "property_value": "В 7,5",
          "property_price_impact": 33
        },
        {
          "property_name": "Колір",
          "property_value": "Червоний",
          "property_price_impact": 22
        }
      ],
      "quantity": 2
    }
  ]
}
```
two unpurchase product
```json
{
    "id": 1,
    "purchasedProduct": [],
    "unpurchasedProduct": [
        {
            "id": 2,
            "product_properties": [
                {
                    "property_name": "Клас",
                    "property_value": "В 7,5",
                    "property_price_impact": 33
                },
                {
                    "property_name": "Колір",
                    "property_value": "Червоний",
                    "property_price_impact": 22
                }
            ],
            "quantity": 2
        },
        {
            "id": 3,
            "product_properties": [
                {
                    "property_name": "Колір",
                    "property_value": "Сірий",
                    "property_price_impact": 12
                }
            ],
            "quantity": 1
        }
    ]
}
```

## 2. Add product to shopping cart
### User can add specific product with specific set of properties which will be saving in db
### Security: PRIVATE
### Method: GET
```
/api/v1/cart/add/product/{product_id}
```

### Request
```json
{
    "quantity": 2,
    "product_properties": [
        {
            "property_price_impact": "33",
            "property_name": "Клас",
            "property_value": "В 7,5"
        },
        {
            "property_price_impact": "22",
            "property_name": "Колір",
            "property_value": "Червоний"
        }
    ]
}
```

### Response:
```json
{
  "id": 2,
  "product": {
    "id": 1,
    "product_name": "rrr",
    "price": 222
  },
  "product_properties": [
    {
      "property_name": "Клас",
      "property_value": "В 7,5",
      "property_price_impact": 33
    },
    {
      "property_name": "Колір",
      "property_value": "Червоний",
      "property_price_impact": 22
    }
  ],
  "quantity": 2
}
```

## 3. Update purchase product
### User can update purchase product in any time by VALID properties from original product
### User can update information about purchase product by new properties or quantity of product 
### There is behaviour could be applied before purchase when User can change his mind about properties, example want change color or add shipment or quantity of product 
### Security: PRIVATE
### Method: GET
```
/api/v1/cart/{purchase_product_id}
```

### Request
```json
{
  "quantity": 4,
  "product_properties": [
    {
      "property_price_impact": "33",
      "property_name": "Клас",
      "property_value": "В 7,5"
    },
    {
      "property_price_impact": "22",
      "property_name": "Колір",
      "property_value": "Червоний"
    },
    {
      "property_price_impact": "11",
      "property_name": "Твердість",
      "property_value": "міцний"
    },
    {
      "property_price_impact": "23",
      "property_name": "Колір",
      "property_value": "Зелений"
    }
  ]
}
```

### Response:
```json
{
  "id": 2,
  "product": {
    "id": 1,
    "product_name": "rrr",
    "price": 222
  },
  "product_properties": [
    {
      "property_name": "Клас",
      "property_value": "В 7,5",
      "property_price_impact": 33
    },
    {
      "property_name": "Колір",
      "property_value": "Червоний",
      "property_price_impact": 22
    },
    {
      "property_name": "Твердість",
      "property_value": "міцний",
      "property_price_impact": 11
    },
    {
      "property_name": "Колір",
      "property_value": "Зелений",
      "property_price_impact": 23
    }
  ],
  "quantity": 4
}
```

## 3.1. Show user shopping cart after one of purchase product was updated
### Show own user shopping cart. User can update purchase product in any time by VALID properties from original product
### Security: PRIVATE
### Method: GET
```
/api/v1/cart
```
### Response:
```json
{
  "id": 1,
  "purchasedProduct": [],
  "unpurchasedProduct": [
    {
      "id": 2,
      "product_properties": [
        {
          "property_name": "Клас",
          "property_value": "В 7,5",
          "property_price_impact": 33
        },
        {
          "property_name": "Колір",
          "property_value": "Червоний",
          "property_price_impact": 22
        },
        {
          "property_name": "Твердість",
          "property_value": "міцний",
          "property_price_impact": 11
        },
        {
          "property_name": "Колір",
          "property_value": "Зелений",
          "property_price_impact": 23
        }
      ],
      "quantity": 4
    },
    {
      "id": 3,
      "product_properties": [
        {
          "property_name": "Колір",
          "property_value": "Сірий",
          "property_price_impact": 12
        }
      ],
      "quantity": 1
    }
  ]
}
```

## 3.2. Show user shopping cart after one of purchase product was deleted
### Show own user shopping cart. User can deleted purchase product in any time
### Security: PRIVATE
### Method: DELETE
```
/api/v1/cart
```
### Response:
```json
{
  "id": 1,
  "purchasedProduct": [],
  "unpurchasedProduct": [
    {
      "id": 3,
      "product_properties": [
        {
          "property_name": "Колір",
          "property_value": "Сірий",
          "property_price_impact": 12
        }
      ],
      "quantity": 1
    }
  ]
}
```


## 4. Checkout
### User can follow to checkout flow in any time
### In request should be added specific purchase product ids
### Security: PRIVATE
### Method: POST
```
api/v1/cartcheckout
```

### Request with invalid ids
```json
{
  "purchaseProductIds": [1, 2]
}
```

### Response:
```json
{
  "errors": {
    "purchaseProductIds.id": [
      {
        "message": "Invalid entity identifier",
        "code": "0192678f-b64f-76fe-82c8-7a0cb039eb23"
      },
      {
        "message": "Invalid entity identifier",
        "code": "0192678f-b64f-76fe-82c8-7a0cb039eb23"
      }
    ]
  }
}
```


### Request with valid purchase ids
```json
{
    "purchaseProductIds": [3, 4]
}
```

### Response:

```json
{
  "order": {
    "id": 2,
    "total_amount": 610,
    "description": "Ваше замовлення: Новий: в кількості: 1 одиниць\n\nНазва: Колір, Значення: Сірий, Плюс до ціни продкта: 12\nВаше замовлення: rrr: в кількості: 2 одиниць\n\nНазва: Клас, Значення: В 7,5, Плюс до ціни продкта: 33\nНазва: Колір, Значення: Червоний, Плюс до ціни продкта: 22\n",
    "quantity_product": "1",
    "liq_pay_status": null,
    "liq_pay_response":"sensitive info",
    "liq_pay_order_id": "2-1734260451",
    "telegram_user_id": null,
    "client_user_id": {
      "id": 1,
      "uuid": "0193bb9c-f4a4-710a-98c9-43f0164ea604",
      "email": "q@q.com"
    },
    "product_properties": [],
    "phone": null,
    "purchaseProduct": []
  },
  "liqpay": {
    "result": "ok",
    "action": "pay",
    "amount": 610,
    "answer_text": "Privat24",
    "bot_channel": "Privat24",
    "bot_in_contacts": false,
    "bot_name": "Privatbank",
    "bot_url": "Privat24",
    "currency": "UAH",
    "description": "Ваше замовлення: Новий: в кількості: 1 одиниць\n\nНазва: Колір, Значення: Сірий, Плюс до ціни продкта: 12\nВаше замовлення: rrr: в кількості: 2 одиниць\n\nНазва: Клас, Значення: В 7,5, Плюс до ціни продкта: 33\nНазва: Колір, Значення: Червоний, Плюс до ціни продкта: 22\n",
    "href": "sensitive info",
    "id": 10347486,
    "order_id": "2-1734260451",
    "receiver_type": "phone",
    "receiver_value": "sensitive info",
    "status": "invoice_wait",
    "token": "sensitive info"
  },
  "link": "sensitive info"
}
```

## 4.1. Show user shopping cart after checkout
### Show own user shopping cart. Checkout so all product which user bought in `purchasedProduct` key. And `unpurchasedProduct` empty now
### Security: PRIVATE
### Method: DELETE
```
/api/v1/cart
```
### Response:
```json
{
  "id": 1,
  "purchasedProduct": [
    {
      "id": 3,
      "product_properties": [
        {
          "property_name": "Колір",
          "property_value": "Сірий",
          "property_price_impact": 12
        }
      ],
      "quantity": 1
    },
    {
      "id": 4,
      "product_properties": [
        {
          "property_name": "Клас",
          "property_value": "В 7,5",
          "property_price_impact": 33
        },
        {
          "property_name": "Колір",
          "property_value": "Червоний",
          "property_price_impact": 22
        }
      ],
      "quantity": 2
    }
  ],
  "unpurchasedProduct": []
}
```

## 5. Show user all orders
### Show user all orders after one product was bought directly and two product was bought through CHECKOUT flow 
### Security: PRIVATE
### Method: GET
```
api/v1/product/user-orders
```
### Response:
```json
[
  {
    "id": 1,
    "total_amount": 222,
    "description": "Ваше замовлення: rrr: в кількості: 1 одиниць",
    "quantity_product": "1",
    "liq_pay_status": "success",
    "liq_pay_response":"sensitive info",
    "liq_pay_order_id": "1-1734019485",
    "telegram_user_id": null,
    "client_user_id": {
      "id": 1,
      "uuid": "0193bb9c-f4a4-710a-98c9-43f0164ea604",
      "email": "q@q.com"
    },
    "product_id": {
      "id": 1,
      "product_name": "rrr"
    },
    "product_properties": [],
    "phone": null,
    "purchaseProduct": []
  },
  {
    "id": 2,
    "total_amount": 610,
    "description": "Ваше замовлення: Новий: в кількості: 1 одиниць\n\nНазва: Колір, Значення: Сірий, Плюс до ціни продкта: 12\nВаше замовлення: rrr: в кількості: 2 одиниць\n\nНазва: Клас, Значення: В 7,5, Плюс до ціни продкта: 33\nНазва: Колір, Значення: Червоний, Плюс до ціни продкта: 22\n",
    "quantity_product": "1",
    "liq_pay_status": "success",
    "liq_pay_response":"sensitive info",
    "liq_pay_order_id": "2-1734260451",
    "telegram_user_id": null,
    "client_user_id": {
      "id": 1,
      "uuid": "0193bb9c-f4a4-710a-98c9-43f0164ea604",
      "email": "q@q.com"
    },
    "product_id": null,
    "product_properties": [],
    "phone": null,
    "purchaseProduct": [
      {
        "id": 3,
        "product": {
          "id": 2,
          "product_name": "Новий"
        },
        "product_properties": [
          {
            "property_name": "Колір",
            "property_value": "Сірий",
            "property_price_impact": 12
          }
        ],
        "quantity": 1
      },
      {
        "id": 4,
        "product": {
          "id": 1,
          "product_name": "rrr"
        },
        "product_properties": [
          {
            "property_name": "Клас",
            "property_value": "В 7,5",
            "property_price_impact": 33
          },
          {
            "property_name": "Колір",
            "property_value": "Червоний",
            "property_price_impact": 22
          }
        ],
        "quantity": 2
      }
    ]
  }
]
```


