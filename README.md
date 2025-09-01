# Overview

To ensure secure and compliant transmission of event data from your servers to Netcore’s platform, we support AES-256 encryption in transit. This document outlines supported encryption methods, recommended practices, and how to enable encrypted event ingestion via our APIs.

# Why AES-256?

AES-256 (Advanced Encryption Standard with 256-bit keys) is an industry-leading encryption method used for secure data exchange. It provides:

- Strong security: Highly resistant to brute-force attacks
- Wide adoption: Used by governments, financial institutions, and enterprises
- Performance: Optimized for modern systems and fast execution

# Supported Encryption Methods

![Supported Methods](images/img_1.png)

Follow the below steps for encryoting the data and pushing to CE dashboard.

# Step 1

Generate a SECRET_KEY and SECRET_IV on your system and share the data with us. The KEY and IV will be configured in our backend post which you can continue with the payload encryption and sending the encrypted data to CE dashboard.

- Here is a [Python script](script/aes256_key_iv_generator_script.py) which can generate the SECRET_KEY and SECRET_IV.

- To run the script use the command

`python3 aes256_key_iv_generator_script.py`

Sharing a sample result of running the above command.

![Result](images/img_2.png)
<img src="images/img_2.png" alt="Alt Text" width="500" height="300" align="center">

Now the generated key and iv can be used to encrypt the payload data.

# Step 2

- Here is a [Python script](script/aes256_data_encryption_script.py) which can be used to encrypt the payload data.

- Sharing the sample usage of the script to encrypt data.

```
python3 aes256_data_encryption_script.py '[{
        "asset_id": "392234ga43t3q4t3026227c3453gqe5",
        "activity_name": "form_submitted",
        "timestamp": "2025-08-26T12:05:43Z",
        "identity": "9999911111",
        "activity_source": "web",
        "activity_params": {
            "user_name": "Mahesh",
            "reason": "test 123",
            "day" : "Tuesday"
        }
    }    
]'
```

- The resultant of the above command will be encrypted data. Sample given below.

  `Encrypted String (Base64): ENtfL3peNnIzXzHBgHqb9bIOoundUGk9scPyPoKrwinCalrrfXZUnm3bNiGBdV3/A+FoqfTLReN5nkr31iQ8Jn9kSeoWlkGeAbsIZUrRsbhB36vzkKGQW12zp/KMBk5tMPlXScHSwhM0GP8lPejtWMsVNbhsOGsPGdW6rY4ZNaFFhyZq3rJIH19HS0f65yVfFXuvLuzoZCudsLa/XRZTZjEhNQobsVq35pxtZpdhl3Tdy7k0rSnbHkIHZ8RMtH22nOpwfbTh+N37T4nI8N0PtBKttwzrHD7t3llNQruhIykttprqAYdDrZF8h4XzQHsNH1h4zck2bUVHY+tSvQIUAAm2xEdpRkTjRCaXJsMql3J0zUAYSorBsS9ncYNeuNKD7JwlLjFsbquVVmZCW3PV+tT/ftSy7u9L89ChZZsl2LaRa1NPpm3AvjrsJQ6Vbo10YhH9dDegYITLJ81qskFBP1uz/tJlxIQlSn/OPeTEXD/cFie2eJjG/LLi+s91cqeWi3vstC3O+e6pni2yZMIP/V0tnFkHzlQD9hamsxgTQGs=`

- Additionally the shared Python script also tests for decrypting the data. So the resultant would also show the decrypted payload as well.

- Key points to remember in the above payload
  - asset_id must be picked from the panel.
  - actvity_name must be the desired event name.
  - timestamp must be current timestamp / events occurence time in UTC format.
  - identity value must be the primary key of the panel.
  - activity_source can be web if web asset is used or app if app asset is used.
  - activity_params must be parameters for the event.
 
# Step 3

This is the final step in the implemenation of the passing encrypted data to CE dashboard.

Use the below curl command to send the data.

```
curl --location 'https://api2.netcoresmartech.com/v1/activity/upload' \
--header 'Content-Type: application/json' \
--header 'x-eCode: 11256' \
--header 'x-asset: 392ae07d8b69fe21423e6d9e026227c5' \
--header 'Authorization: Bearer 06cf420b244adc210b05da30e4731340' \
--data '{

"encryptedRequest":"6UbL6LFCeSR2HyFYBXXNjrvq4WCNLWb7K2lAXnVBAjmngco59l5FfeqsG8M7pLHfR20DTCn5QX3WfBcdqeImhlH5pDDtcJ8lrxe6UDQdiusIfdbiXHbPfET3OSAt6EYhzVgjMov7dKESBpSsawqSATcJsqYT81X63mXVXyEYof4k9nwH/u7nEpY/+6bbMTa4uqIEUdFKgt/xDEt1LoD7DTHTkiFr8LY0UVID04GXY2T7Lr8cEiB/7fGwTeXyrKrGngFxMD/hAU5xMqsb4Kr/qlX7mBMugzJ/n8GgULIXdDJSN55M6BeTjsWR1EETIxNUse/u8KUftfOigkYvEEhROnbPINZH71gZcyoMmyIsJMsGx3RpXBNqqE4p29BOkgfCauT0hrpT6kRGaOetMD9/xe7H8pYdTisUF4s26v9+awf6dhnhHGNSxUmBBJL/BHfG5+cPwPyTOcUQ/Hos/+Xrp/1nNELAygmCrnB8EDs/IQE="

}'
```

In the above curl request

- 11256 points to AES_256_CBC_BASE64 Algorithm.
- x-asset is the same asset id as in the payload encryption.
- API key of the panel must be used under Bearer token.
  
