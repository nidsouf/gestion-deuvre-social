<?php
/**
 * api/documentation.php - توثيق API باستخدام OpenAPI 3.0
 * يمكن الوصول إليه عبر: http://localhost/api/documentation.php
 */
header('Content-Type: application/json');
?>
{
  "openapi": "3.0.0",
  "info": {
    "title": "نظام إدارة الاقتطاعات والمنح الاجتماعية API",
    "description": "واجهة برمجة تطبيقات RESTful للنظام",
    "version": "1.0.0",
    "contact": {
      "name": "لجنة الخدمات الاجتماعية",
      "email": "social@example.com"
    }
  },
  "servers": [
    {
      "url": "http://localhost:3000/api",
      "description": "خادم التطوير المحلي"
    }
  ],
  "components": {
    "securitySchemes": {
      "sessionAuth": {
        "type": "apiKey",
        "in": "cookie",
        "name": "PHPSESSID"
      }
    },
    "schemas": {
      "Employee": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" },
          "name": { "type": "string" },
          "category": { "type": "string", "enum": ["Permanent", "Contract"] },
          "hire_date": { "type": "string", "format": "date" }
        }
      },
      "Deduction": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" },
          "employee_id": { "type": "integer" },
          "source_id": { "type": "integer" },
          "monthly_amount": { "type": "number" },
          "total_months": { "type": "integer" },
          "start_date": { "type": "string", "format": "date" },
          "end_date": { "type": "string", "format": "date" },
          "is_loan": { "type": "boolean" }
        }
      },
      "Grant": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" },
          "employee_id": { "type": "integer" },
          "grant_id": { "type": "integer" },
          "grant_date": { "type": "string", "format": "date" },
          "notes": { "type": "string" }
        }
      },
      "Budget": {
        "type": "object",
        "properties": {
          "id": { "type": "integer" },
          "year": { "type": "integer" },
          "initial_budget": { "type": "number" },
          "remaining_budget": { "type": "number" }
        }
      },
      "Error": {
        "type": "object",
        "properties": {
          "error": { "type": "string" }
        }
      },
      "Success": {
        "type": "object",
        "properties": {
          "success": { "type": "boolean" },
          "message": { "type": "string" },
          "id": { "type": "integer" }
        }
      }
    }
  },
  "security": [{ "sessionAuth": [] }],
  "tags": [
    { "name": "Employees", "description": "إدارة الموظفين" },
    { "name": "Deductions", "description": "إدارة الاقتطاعات" },
    { "name": "Grants", "description": "إدارة المنح" },
    { "name": "Budget", "description": "إدارة الميزانية" },
    { "name": "Reports", "description": "التقارير" }
  ],
  "paths": {
    "/employees/list": {
      "get": {
        "tags": ["Employees"],
        "summary": "الحصول على قائمة الموظفين",
        "responses": {
          "200": {
            "description": "نجاح",
            "content": {
              "application/json": {
                "schema": {
                  "type": "array",
                  "items": { "$ref": "#/components/schemas/Employee" }
                }
              }
            }
          }
        }
      }
    },
    "/employees/{id}": {
      "get": {
        "tags": ["Employees"],
        "summary": "الحصول على موظف محدد",
        "parameters": [
          { "name": "id", "in": "path", "required": true, "schema": { "type": "integer" } }
        ],
        "responses": {
          "200": { "description": "نجاح", "content": { "application/json": { "schema": { "$ref": "#/components/schemas/Employee" } } } },
          "404": { "description": "الموظف غير موجود" }
        }
      }
    },
    "/deductions/list": {
      "get": {
        "tags": ["Deductions"],
        "summary": "الحصول على قائمة الاقتطاعات",
        "parameters": [
          { "name": "year", "in": "query", "schema": { "type": "integer" } },
          { "name": "month", "in": "query", "schema": { "type": "integer" } },
          { "name": "source_id", "in": "query", "schema": { "type": "integer" } },
          { "name": "employee_id", "in": "query", "schema": { "type": "integer" } }
        ],
        "responses": { "200": { "description": "نجاح", "content": { "application/json": { "schema": { "type": "array", "items": { "$ref": "#/components/schemas/Deduction" } } } } } }
      }
    },
    "/deductions/create": {
      "post": {
        "tags": ["Deductions"],
        "summary": "إضافة اقتطاع جديد",
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": { "$ref": "#/components/schemas/Deduction" }
            }
          }
        },
        "responses": {
          "201": { "description": "تم الإنشاء", "content": { "application/json": { "schema": { "$ref": "#/components/schemas/Success" } } } },
          "400": { "description": "بيانات غير صحيحة" }
        }
      }
    },
    "/grants/list": {
      "get": {
        "tags": ["Grants"],
        "summary": "الحصول على قائمة المنح",
        "responses": { "200": { "description": "نجاح", "content": { "application/json": { "schema": { "type": "array", "items": { "$ref": "#/components/schemas/Grant" } } } } } }
      }
    },
    "/grants/create": {
      "post": {
        "tags": ["Grants"],
        "summary": "إضافة منحة لموظف",
        "requestBody": {
          "required": true,
          "content": {
            "application/json": {
              "schema": { "$ref": "#/components/schemas/Grant" }
            }
          }
        },
        "responses": {
          "201": { "description": "تم الإنشاء" },
          "400": { "description": "بيانات غير صحيحة" }
        }
      }
    },
    "/budget/status": {
      "get": {
        "tags": ["Budget"],
        "summary": "الحصول على حالة الميزانية",
        "responses": { "200": { "description": "نجاح", "content": { "application/json": { "schema": { "$ref": "#/components/schemas/Budget" } } } } }
      }
    },
    "/reports/annual": {
      "get": {
        "tags": ["Reports"],
        "summary": "التقرير السنوي",
        "parameters": [
          { "name": "year", "in": "query", "required": true, "schema": { "type": "integer" } }
        ],
        "responses": { "200": { "description": "نجاح", "content": { "application/json": { "schema": { "type": "object" } } } } }
      }
    },
    "/reports/monthly": {
      "get": {
        "tags": ["Reports"],
        "summary": "التقرير الشهري",
        "parameters": [
          { "name": "year", "in": "query", "required": true, "schema": { "type": "integer" } },
          { "name": "month", "in": "query", "required": true, "schema": { "type": "integer" } }
        ],
        "responses": { "200": { "description": "نجاح", "content": { "application/json": { "schema": { "type": "object" } } } } }
      }
    },
    "/reports/quarterly": {
      "get": {
        "tags": ["Reports"],
        "summary": "التقرير الثلاثي",
        "parameters": [
          { "name": "year", "in": "query", "required": true, "schema": { "type": "integer" } },
          { "name": "quarter", "in": "query", "required": true, "schema": { "type": "integer", "enum": [1,2,3,4] } }
        ],
        "responses": { "200": { "description": "نجاح" } }
      }
    }
  }
}