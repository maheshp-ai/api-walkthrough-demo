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

# Step1

Generate a SECRET_KEY and SECRET_IV on your system and share the data with us. The KEY and IV will be configured in our backend post which you can continue with the payload encryption and sending the encrypted data to CE dashboard.

- Here is a python file which can generate the SECRET_KEY and SECRET_IV ![script](script/aes256_key_iv_generator_script.py)
- To run the script use the command *python3 aes256_key_iv_generator_script.py*
